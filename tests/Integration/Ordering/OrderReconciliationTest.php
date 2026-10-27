<?php

declare(strict_types=1);

namespace Tests\Integration\Ordering;

use App\Catalog\Infrastructure\Persistence\Doctrine\Fixtures\CatalogFixtures;
use App\Inventory\Domain\Entity\StockPosition;
use App\Inventory\Domain\Repository\StockPositionRepositoryInterface;
use App\Inventory\Domain\ValueObject\ProductId;
use App\Inventory\Infrastructure\Integration\Ordering\OrderSubmittedReservationConsumer;
use App\Ordering\Application\Command\ReconcileStock\ReconcileStockCommand;
use App\Ordering\Application\Command\SubmitOrder\SubmitOrderCommand;
use App\Ordering\Domain\Collection\OrderItemCollection;
use App\Ordering\Domain\DataProvider\OrderDataProviderInterface;
use App\Ordering\Domain\Entity\Order;
use App\Ordering\Domain\Entity\OrderItem;
use App\Ordering\Domain\Enum\OrderStatus;
use App\Ordering\Domain\Repository\OrderRepositoryInterface;
use App\Ordering\Domain\ValueObject\BuyerId;
use App\Ordering\Domain\ValueObject\OrderId as OrderingOrderId;
use App\Ordering\Presentation\Contracts\Event\OrderSubmittedEvent;
use App\Shared\Application\Bus\Command\CommandBusInterface;
use App\Shared\Infrastructure\Messaging\Outbox\Relay\OutboxRelay;
use Tests\Integration\IntegrationTestCase;

final class OrderReconciliationTest extends IntegrationTestCase
{
    private CommandBusInterface $commandBus;
    private OrderRepositoryInterface $orderRepo;
    private OrderDataProviderInterface $orderProvider;
    private StockPositionRepositoryInterface $stockRepo;
    private OutboxRelay $outboxRelay;
    private OrderSubmittedReservationConsumer $reservationConsumer;

    protected function setUp(): void
    {
        parent::setUp();
        $container = self::getContainer();
        $this->commandBus = $container->get(CommandBusInterface::class);
        $this->orderRepo = $container->get(OrderRepositoryInterface::class);
        $this->orderProvider = $container->get(OrderDataProviderInterface::class);
        $this->stockRepo = $container->get(StockPositionRepositoryInterface::class);
        $this->outboxRelay = $container->get(OutboxRelay::class);
        $this->reservationConsumer = $container->get(OrderSubmittedReservationConsumer::class);

        $fixtures = new CatalogFixtures();
        $fixtures->load($this->em);

        $conn = $this->em->getConnection();
        $conn->executeStatement("DELETE FROM ordering.order_items WHERE order_id LIKE '00000000-0000-4000-9000-%'");
        $conn->executeStatement("DELETE FROM ordering.orders WHERE id LIKE '00000000-0000-4000-9000-%'");
        $conn->executeStatement("DELETE FROM inventory.reservation_items WHERE order_id LIKE '00000000-0000-4000-9000-%'");
        $conn->executeStatement("DELETE FROM inventory.reservations WHERE order_id LIKE '00000000-0000-4000-9000-%'");
        $conn->executeStatement("DELETE FROM inventory.stock_positions WHERE product_id = '".CatalogFixtures::PRODUCT_KEYBOARD."'");
        $conn->executeStatement('DELETE FROM shared.outbox_messages');
        $conn->executeStatement('DELETE FROM shared.processed_messages');
        $conn->executeStatement("DELETE FROM public.messenger_messages WHERE queue_name = 'async'");
    }

    public function testOrderTransitionsToConfirmedWhenStockReserved(): void
    {
        $prodId = CatalogFixtures::PRODUCT_KEYBOARD;
        $this->stockRepo->save(new StockPosition(new ProductId($prodId), onHand: 10, reserved: 0));
        $this->em->flush();
        $this->em->clear();

        $orderIdStr = '00000000-0000-4000-9000-000000000001';
        $buyerIdStr = 'buyer-reconcile-1';
        $orderId = new OrderingOrderId($orderIdStr);

        $order = Order::createDraft($orderId, new BuyerId($buyerIdStr));
        $order->replaceItems(new OrderItemCollection([
            new OrderItem(new \App\Ordering\Domain\ValueObject\ProductId($prodId), 2),
        ]));
        $this->orderRepo->save($order);
        $this->em->flush();
        $this->em->clear();

        // 1. Submit order
        $this->commandBus->dispatch(new SubmitOrderCommand($orderIdStr, $buyerIdStr));

        $order = $this->orderProvider->findById($orderId);
        self::assertSame(OrderStatus::PENDING_STOCK, $order->status());
        self::assertNotNull($order->nextCheckAt());

        // 2. Relay outbox & consume by Inventory
        $conn = $this->em->getConnection();
        $body = $conn->fetchOne('SELECT body FROM shared.outbox_messages');
        /** @var OrderSubmittedEvent $event */
        $event = unserialize(base64_decode((string) $body, true));

        $this->outboxRelay->relayOne();
        ($this->reservationConsumer)($event);
        $this->em->flush();
        $this->em->clear();

        // 3. Run reconciliation command
        $this->commandBus->dispatch(new ReconcileStockCommand(50));
        $this->em->clear();

        // Order is now confirmed
        $confirmedOrder = $this->orderProvider->findById($orderId);
        self::assertSame(OrderStatus::CONFIRMED, $confirmedOrder->status());
        self::assertNull($confirmedOrder->rejectionReason());
        self::assertNull($confirmedOrder->nextCheckAt());

        // 4. Repeated reconciliation does not alter confirmed status
        $this->commandBus->dispatch(new ReconcileStockCommand(50));
        $this->em->clear();

        $stillConfirmed = $this->orderProvider->findById($orderId);
        self::assertSame(OrderStatus::CONFIRMED, $stillConfirmed->status());
    }

    public function testOrderTransitionsToRejectedWhenStockInsufficient(): void
    {
        $prodId = CatalogFixtures::PRODUCT_KEYBOARD;
        $this->stockRepo->save(new StockPosition(new ProductId($prodId), onHand: 1, reserved: 0));
        $this->em->flush();
        $this->em->clear();

        $orderIdStr = '00000000-0000-4000-9000-000000000002';
        $buyerIdStr = 'buyer-reconcile-2';
        $orderId = new OrderingOrderId($orderIdStr);

        // Order asks for 5 keyboards when only 1 is available
        $order = Order::createDraft($orderId, new BuyerId($buyerIdStr));
        $order->replaceItems(new OrderItemCollection([
            new OrderItem(new \App\Ordering\Domain\ValueObject\ProductId($prodId), 5),
        ]));
        $this->orderRepo->save($order);
        $this->em->flush();
        $this->em->clear();

        // 1. Submit order
        $this->commandBus->dispatch(new SubmitOrderCommand($orderIdStr, $buyerIdStr));

        // 2. Relay outbox & consume by Inventory -> stock insufficient
        $conn = $this->em->getConnection();
        $body = $conn->fetchOne('SELECT body FROM shared.outbox_messages');
        /** @var OrderSubmittedEvent $event */
        $event = unserialize(base64_decode((string) $body, true));

        $this->outboxRelay->relayOne();
        ($this->reservationConsumer)($event);
        $this->em->flush();
        $this->em->clear();

        // 3. Run reconciliation command
        $this->commandBus->dispatch(new ReconcileStockCommand(50));
        $this->em->clear();

        // Order is now rejected with rejectionReason
        $rejectedOrder = $this->orderProvider->findById($orderId);
        self::assertSame(OrderStatus::REJECTED, $rejectedOrder->status());
        self::assertSame('insufficient_stock', $rejectedOrder->rejectionReason());
        self::assertNull($rejectedOrder->nextCheckAt());

        // 4. Repeated reconciliation does not alter rejected status
        $this->commandBus->dispatch(new ReconcileStockCommand(50));
        $this->em->clear();

        $stillRejected = $this->orderProvider->findById($orderId);
        self::assertSame(OrderStatus::REJECTED, $stillRejected->status());
        self::assertSame('insufficient_stock', $stillRejected->rejectionReason());
    }

    public function testOrderRemainsPendingWhenInventoryNotProcessedYet(): void
    {
        $prodId = CatalogFixtures::PRODUCT_KEYBOARD;
        $this->stockRepo->save(new StockPosition(new ProductId($prodId), onHand: 10, reserved: 0));
        $this->em->flush();
        $this->em->clear();

        $orderIdStr = '00000000-0000-4000-9000-000000000003';
        $buyerIdStr = 'buyer-reconcile-3';
        $orderId = new OrderingOrderId($orderIdStr);

        $order = Order::createDraft($orderId, new BuyerId($buyerIdStr));
        $order->replaceItems(new OrderItemCollection([
            new OrderItem(new \App\Ordering\Domain\ValueObject\ProductId($prodId), 2),
        ]));
        $this->orderRepo->save($order);
        $this->em->flush();
        $this->em->clear();

        // 1. Submit order
        $this->commandBus->dispatch(new SubmitOrderCommand($orderIdStr, $buyerIdStr));

        // 2. Inventory has NOT consumed event yet -> result is not_found
        // Run reconciliation
        $this->commandBus->dispatch(new ReconcileStockCommand(50));
        $this->em->clear();

        $pendingOrder = $this->orderProvider->findById($orderId);
        self::assertSame(OrderStatus::PENDING_STOCK, $pendingOrder->status());
        self::assertNotNull($pendingOrder->nextCheckAt());
        $postponedCheck = $pendingOrder->nextCheckAt();

        // 3. Immediately running reconciliation again skips this order because nextCheckAt > now
        $conn = $this->em->getConnection();
        $body = $conn->fetchOne('SELECT body FROM shared.outbox_messages');
        /** @var OrderSubmittedEvent $event */
        $event = unserialize(base64_decode((string) $body, true));
        $this->outboxRelay->relayOne();
        ($this->reservationConsumer)($event);
        $this->em->flush();
        $this->em->clear();

        // Even though reservation is ready, reconciliation with current time skips it if check was postponed
        $this->commandBus->dispatch(new ReconcileStockCommand(50));
        $this->em->clear();

        $stillPendingUntilNextCheck = $this->orderProvider->findById($orderId);
        self::assertSame(OrderStatus::PENDING_STOCK, $stillPendingUntilNextCheck->status());

        // 4. Once time advances past nextCheckAt, reconciliation confirms the order
        $conn->executeStatement("UPDATE ordering.orders SET next_check_at = NOW() - INTERVAL '1 second' WHERE id = '{$orderIdStr}'");

        $this->commandBus->dispatch(new ReconcileStockCommand(50));
        $this->em->clear();

        $finalOrder = $this->orderProvider->findById($orderId);
        self::assertSame(OrderStatus::CONFIRMED, $finalOrder->status());
        self::assertNull($finalOrder->nextCheckAt());
    }
}
