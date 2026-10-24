<?php

declare(strict_types=1);

namespace Tests\Integration\Ordering;

use App\Catalog\Infrastructure\Persistence\Doctrine\Fixtures\CatalogFixtures;
use App\Inventory\Domain\DataProvider\StockPositionDataProviderInterface;
use App\Inventory\Domain\Entity\StockPosition;
use App\Inventory\Domain\Repository\StockPositionRepositoryInterface;
use App\Inventory\Domain\ValueObject\ProductId;
use App\Inventory\Infrastructure\Messaging\Inbox\ReserveStock\ReserveStockInboxHandler;
use App\Inventory\Infrastructure\Messaging\Inbox\ReserveStock\ReserveStockInboxItem;
use App\Inventory\Infrastructure\Messaging\Inbox\ReserveStock\ReserveStockInboxMessage;
use App\Inventory\Presentation\Contracts\InventoryContractInterface;
use App\Ordering\Application\Command\SubmitOrder\SubmitOrderCommand;
use App\Ordering\Domain\Collection\OrderItemCollection;
use App\Ordering\Domain\DataProvider\OrderDataProviderInterface;
use App\Ordering\Domain\Entity\Order;
use App\Ordering\Domain\Entity\OrderItem;
use App\Ordering\Domain\Repository\OrderRepositoryInterface;
use App\Ordering\Domain\ValueObject\BuyerId;
use App\Ordering\Domain\ValueObject\OrderId as OrderingOrderId;
use App\Ordering\Infrastructure\Messaging\Outbox\RequestReservation\RequestReservationOutboxHandler;
use App\Ordering\Infrastructure\Messaging\Outbox\RequestReservation\RequestReservationOutboxItem;
use App\Ordering\Infrastructure\Messaging\Outbox\RequestReservation\RequestReservationOutboxMessage;
use App\Shared\Application\Bus\Command\CommandBusInterface;
use Tests\Integration\IntegrationTestCase;

final class OrderSubmissionOutboxDeliveryTest extends IntegrationTestCase
{
    private CommandBusInterface $commandBus;
    private OrderRepositoryInterface $orderRepo;
    private OrderDataProviderInterface $orderProvider;
    private InventoryContractInterface $inventoryContract;
    private StockPositionRepositoryInterface $stockRepo;
    private StockPositionDataProviderInterface $stockProvider;
    private RequestReservationOutboxHandler $outboxHandler;
    private ReserveStockInboxHandler $inboxHandler;

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
        $this->outboxHandler = $container->get(RequestReservationOutboxHandler::class);
        $this->inboxHandler = $container->get(ReserveStockInboxHandler::class);

        $fixtures = new CatalogFixtures();
        $fixtures->load($this->em);

        $conn = $this->em->getConnection();
        $conn->executeStatement("DELETE FROM ordering.order_items WHERE order_id LIKE '00000000-0000-4000-8000-000000000c%'");
        $conn->executeStatement("DELETE FROM ordering.orders WHERE id LIKE '00000000-0000-4000-8000-000000000c%'");
        $conn->executeStatement("DELETE FROM inventory.reservation_items WHERE order_id LIKE '00000000-0000-4000-8000-000000000c%'");
        $conn->executeStatement("DELETE FROM inventory.reservations WHERE order_id LIKE '00000000-0000-4000-8000-000000000c%'");
        $conn->executeStatement("DELETE FROM inventory.stock_positions WHERE product_id = '".CatalogFixtures::PRODUCT_KEYBOARD."'");
    }

    public function testOrderSubmissionDispatchesOutboxAndReachesInventoryInbox(): void
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

        // 2. Outbox handler delivers reservation request to inventory contract
        $outboxMessage = new RequestReservationOutboxMessage($orderIdStr, [
            new RequestReservationOutboxItem($inventoryProd->value, 3),
        ]);
        ($this->outboxHandler)($outboxMessage);

        // Inventory has received task into inbox, but not yet mutated stock synchronously
        $stockBeforeWorker = $this->stockProvider->findByProductId($inventoryProd);
        self::assertSame(0, $stockBeforeWorker->reserved());
        self::assertSame(10, $stockBeforeWorker->available());

        // 3. Simulating async worker consuming inbox task
        $inboxMessage = new ReserveStockInboxMessage($orderIdStr, [
            new ReserveStockInboxItem($inventoryProd->value, 3),
        ]);
        ($this->inboxHandler)($inboxMessage);
        $this->em->flush();
        $this->em->clear();

        // Stock is now reserved
        $stockAfterWorker = $this->stockProvider->findByProductId($inventoryProd);
        self::assertSame(3, $stockAfterWorker->reserved());
        self::assertSame(7, $stockAfterWorker->available());

        $reservationResult = $this->inventoryContract->getReservationResult($orderIdStr);
        self::assertSame('reserved', $reservationResult->status);
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

        // And no reservation in inventory
        $reservationResult = $this->inventoryContract->getReservationResult($orderIdStr);
        self::assertSame('not_found', $reservationResult->status);
    }
}
