<?php

declare(strict_types=1);

namespace Tests\Integration\Ordering;

use App\Catalog\Infrastructure\Persistence\Doctrine\Fixtures\CatalogFixtures;
use App\Inventory\Domain\DataProvider\StockPositionDataProviderInterface;
use App\Inventory\Domain\Entity\StockPosition;
use App\Inventory\Domain\Repository\StockPositionRepositoryInterface;
use App\Inventory\Domain\ValueObject\ProductId;
use App\Inventory\Infrastructure\Integration\Ordering\OrderSubmittedReservationConsumer;
use App\Inventory\Presentation\Contracts\InventoryContractInterface;
use App\Ordering\Application\Command\SubmitOrder\SubmitOrderCommand;
use App\Ordering\Domain\Collection\OrderItemCollection;
use App\Ordering\Domain\DataProvider\OrderDataProviderInterface;
use App\Ordering\Domain\Entity\Order;
use App\Ordering\Domain\Entity\OrderItem;
use App\Ordering\Domain\Repository\OrderRepositoryInterface;
use App\Ordering\Domain\ValueObject\BuyerId;
use App\Ordering\Domain\ValueObject\OrderId as OrderingOrderId;
use App\Ordering\Presentation\Contracts\Event\OrderSubmittedEvent;
use App\Shared\Application\Bus\Command\CommandBusInterface;
use App\Shared\Infrastructure\Messaging\Outbox\Relay\OutboxRelay;
use Tests\Integration\IntegrationTestCase;

final class OrderSubmissionOutboxDeliveryTest extends IntegrationTestCase
{
    private CommandBusInterface $commandBus;
    private OrderRepositoryInterface $orderRepo;
    private OrderDataProviderInterface $orderProvider;
    private InventoryContractInterface $inventoryContract;
    private StockPositionRepositoryInterface $stockRepo;
    private StockPositionDataProviderInterface $stockProvider;
    private OutboxRelay $outboxRelay;
    private OrderSubmittedReservationConsumer $reservationConsumer;

    protected function setUp(): void
    {
        parent::setUp();
        $container = self::getContainer();
        $this->commandBus = $container->get(CommandBusInterface::class);
        $this->orderRepo = $container->get(OrderRepositoryInterface::class);
        $this->orderProvider = $container->get(OrderDataProviderInterface::class);
        $this->inventoryContract = $container->get(InventoryContractInterface::class);
        $this->stockRepo = $container->get(StockPositionRepositoryInterface::class);
        $this->stockProvider = $container->get(StockPositionDataProviderInterface::class);
        $this->outboxRelay = $container->get(OutboxRelay::class);
        $this->reservationConsumer = $container->get(OrderSubmittedReservationConsumer::class);

        $fixtures = new CatalogFixtures();
        $fixtures->load($this->em);

        $conn = $this->em->getConnection();
        $conn->executeStatement("DELETE FROM ordering.order_items WHERE order_id LIKE '00000000-0000-4000-8000-000000000c%'");
        $conn->executeStatement("DELETE FROM ordering.orders WHERE id LIKE '00000000-0000-4000-8000-000000000c%'");
        $conn->executeStatement("DELETE FROM inventory.reservation_items WHERE order_id LIKE '00000000-0000-4000-8000-000000000c%'");
        $conn->executeStatement("DELETE FROM inventory.reservations WHERE order_id LIKE '00000000-0000-4000-8000-000000000c%'");
        $conn->executeStatement("DELETE FROM inventory.stock_positions WHERE product_id = '".CatalogFixtures::PRODUCT_KEYBOARD."'");
        $conn->executeStatement('DELETE FROM shared.outbox_messages');
        $conn->executeStatement('DELETE FROM shared.processed_messages');
        $conn->executeStatement("DELETE FROM public.messenger_messages WHERE queue_name = 'async'");
    }

    public function testOrderSubmissionDispatchesOutboxAndReachesInventoryConsumer(): void
    {
        $prodId = new ProductId(CatalogFixtures::PRODUCT_KEYBOARD);
        $inventoryProd = new ProductId(CatalogFixtures::PRODUCT_KEYBOARD);
        $this->stockRepo->save(new StockPosition($inventoryProd, onHand: 10, reserved: 0));
        $this->em->flush();
        $this->em->clear();

        $orderIdStr = '00000000-0000-4000-8000-000000000c01';
        $buyerIdStr = 'buyer-delivery-test';

        $orderId = new OrderingOrderId($orderIdStr);
        $buyerId = new BuyerId($buyerIdStr);

        $order = Order::createDraft($orderId, $buyerId);
        $order->replaceItems(new OrderItemCollection([
            new OrderItem(new \App\Ordering\Domain\ValueObject\ProductId($prodId->value), 3),
        ]));
        $this->orderRepo->save($order);
        $this->em->flush();
        $this->em->clear();

        // 1. Submit order
        $this->commandBus->dispatch(new SubmitOrderCommand($orderIdStr, $buyerIdStr));

        // Order is now pending_stock
        $order = $this->orderProvider->findById(new OrderingOrderId($orderIdStr));
        self::assertSame('pending_stock', $order->status()->value);

        // 2. Verify outbox contains the published OrderSubmittedEvent
        $conn = $this->em->getConnection();
        $outboxRows = $conn->fetchAllAssociative('SELECT * FROM shared.outbox_messages');
        self::assertCount(1, $outboxRows);

        /** @var OrderSubmittedEvent $event */
        $event = unserialize(base64_decode($outboxRows[0]['body'], true));
        self::assertInstanceOf(OrderSubmittedEvent::class, $event);
        self::assertSame('Ordering', $event->source);
        self::assertSame('ordering.order_submitted', $event->type);
        self::assertSame($orderIdStr, $event->orderId);
        self::assertSame(3, $event->items[0]->quantity);

        // Stock has not changed yet
        $stockBeforeRelay = $this->stockProvider->findByProductId($inventoryProd);
        self::assertSame(0, $stockBeforeRelay->reserved());
        self::assertSame(10, $stockBeforeRelay->available());

        // 3. Outbox relay transfers message to Messenger async transport
        $relayed = $this->outboxRelay->relayOne();
        self::assertTrue($relayed);
        self::assertSame(0, (int) $conn->fetchOne('SELECT COUNT(*) FROM shared.outbox_messages'));
        self::assertSame(1, (int) $conn->fetchOne("SELECT COUNT(*) FROM public.messenger_messages WHERE queue_name = 'async'"));

        // 4. Consumer handles event with idempotent consumer
        ($this->reservationConsumer)($event);
        $this->em->flush();
        $this->em->clear();

        // Stock is now reserved
        $stockAfterConsumer = $this->stockProvider->findByProductId($inventoryProd);
        self::assertSame(3, $stockAfterConsumer->reserved());
        self::assertSame(7, $stockAfterConsumer->available());

        $reservationResult = $this->inventoryContract->getReservationResult($orderIdStr);
        self::assertSame('reserved', $reservationResult->status);

        // 5. Duplicate event does not reserve twice (idempotence)
        ($this->reservationConsumer)($event);
        $this->em->flush();
        $this->em->clear();

        $stockAfterDuplicate = $this->stockProvider->findByProductId($inventoryProd);
        self::assertSame(3, $stockAfterDuplicate->reserved());
        self::assertSame(7, $stockAfterDuplicate->available());
    }

    public function testOrderAndOutboxRollbackTogetherOnFailure(): void
    {
        $orderIdStr = '00000000-0000-4000-8000-000000000c02';
        $buyerIdStr = 'buyer-delivery-test';
        $orderId = new OrderingOrderId($orderIdStr);
        $buyerId = new BuyerId($buyerIdStr);

        $order = Order::createDraft($orderId, $buyerId);
        $order->replaceItems(new OrderItemCollection([
            new OrderItem(new \App\Ordering\Domain\ValueObject\ProductId('prod-nonexistent'), 1),
        ]));
        $this->orderRepo->save($order);
        $this->em->flush();
        $this->em->clear();

        try {
            $this->commandBus->dispatch(new SubmitOrderCommand($orderIdStr, $buyerIdStr));
            self::fail('Expected exception when product not in catalog');
        } catch (\Throwable) {
            // Expected
        }

        $this->em->clear();
        $loadedOrder = $this->orderProvider->findById($orderId);
        self::assertSame('draft', $loadedOrder->status()->value); // Remains draft!

        // And no outbox message created
        $conn = $this->em->getConnection();
        self::assertSame(0, (int) $conn->fetchOne('SELECT COUNT(*) FROM shared.outbox_messages'));

        // And no reservation in inventory
        $reservationResult = $this->inventoryContract->getReservationResult($orderIdStr);
        self::assertSame('not_found', $reservationResult->status);
    }
}
