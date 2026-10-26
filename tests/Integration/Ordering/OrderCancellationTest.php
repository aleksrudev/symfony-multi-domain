<?php

declare(strict_types=1);

namespace Tests\Integration\Ordering;

use App\Catalog\Infrastructure\Persistence\Doctrine\Fixtures\CatalogFixtures;
use App\Inventory\Domain\DataProvider\StockPositionDataProviderInterface;
use App\Inventory\Domain\Entity\StockPosition;
use App\Inventory\Domain\Repository\StockPositionRepositoryInterface;
use App\Inventory\Domain\ValueObject\ProductId;
use App\Inventory\Infrastructure\Integration\Ordering\OrderCancelledReleaseConsumer;
use App\Inventory\Infrastructure\Integration\Ordering\OrderSubmittedReservationConsumer;
use App\Ordering\Application\Command\CancelOrder\CancelOrderCommand;
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
use App\Ordering\Domain\ValueObject\ProductId as OrderingProductId;
use App\Ordering\Presentation\Contracts\Event\OrderCancelledEvent;
use App\Ordering\Presentation\Contracts\Event\OrderSubmittedEvent;
use App\Shared\Application\Bus\Command\CommandBusInterface;
use Tests\Integration\IntegrationTestCase;

final class OrderCancellationTest extends IntegrationTestCase
{
    private CommandBusInterface $commandBus;
    private OrderRepositoryInterface $orderRepo;
    private OrderDataProviderInterface $orderProvider;
    private StockPositionRepositoryInterface $stockRepo;
    private StockPositionDataProviderInterface $stockPositionProvider;
    private OrderSubmittedReservationConsumer $reservationConsumer;
    private OrderCancelledReleaseConsumer $releaseConsumer;

    protected function setUp(): void
    {
        parent::setUp();
        $container = self::getContainer();
        $this->commandBus = $container->get(CommandBusInterface::class);
        $this->orderRepo = $container->get(OrderRepositoryInterface::class);
        $this->orderProvider = $container->get(OrderDataProviderInterface::class);
        $this->stockRepo = $container->get(StockPositionRepositoryInterface::class);
        $this->stockPositionProvider = $container->get(StockPositionDataProviderInterface::class);
        $this->reservationConsumer = $container->get(OrderSubmittedReservationConsumer::class);
        $this->releaseConsumer = $container->get(OrderCancelledReleaseConsumer::class);

        $fixtures = new CatalogFixtures();
        $fixtures->load($this->em);

        $conn = $this->em->getConnection();
        $conn->executeStatement("DELETE FROM ordering.order_items WHERE order_id LIKE '00000000-0000-4000-9100-%'");
        $conn->executeStatement("DELETE FROM ordering.orders WHERE id LIKE '00000000-0000-4000-9100-%'");
        $conn->executeStatement("DELETE FROM inventory.reservation_items WHERE order_id LIKE '00000000-0000-4000-9100-%'");
        $conn->executeStatement("DELETE FROM inventory.reservations WHERE order_id LIKE '00000000-0000-4000-9100-%'");
        $conn->executeStatement("DELETE FROM inventory.stock_positions WHERE product_id = '".CatalogFixtures::PRODUCT_KEYBOARD."'");
        $conn->executeStatement('DELETE FROM shared.outbox_messages');
        $conn->executeStatement('DELETE FROM shared.processed_messages');
        $conn->executeStatement("DELETE FROM public.messenger_messages WHERE queue_name = 'async'");
    }

    public function testBuyerCancelsPendingStockOrder(): void
    {
        $prodId = CatalogFixtures::PRODUCT_KEYBOARD;
        $this->stockRepo->save(new StockPosition(new ProductId($prodId), onHand: 10, reserved: 0));
        $this->em->flush();
        $this->em->clear();

        $orderIdStr = '00000000-0000-4000-9100-000000000001';
        $buyerIdStr = 'buyer-cancel-1';
        $orderId = new OrderingOrderId($orderIdStr);

        $order = Order::createDraft($orderId, new BuyerId($buyerIdStr));
        $order->replaceItems(new OrderItemCollection([
            new OrderItem(new OrderingProductId($prodId), 2),
        ]));
        $this->orderRepo->save($order);
        $this->em->flush();
        $this->em->clear();

        // 1. Submit order
        $this->commandBus->dispatch(new SubmitOrderCommand($orderIdStr, $buyerIdStr));

        // 2. Cancel order
        $this->commandBus->dispatch(new CancelOrderCommand($orderIdStr, $buyerIdStr));

        $order = $this->orderProvider->findById($orderId);
        self::assertSame(OrderStatus::CANCELLING, $order->status());

        // 3. Find and relay outbox cancellation message
        $conn = $this->em->getConnection();
        $outboxRows = $conn->fetchAllAssociative('SELECT id, body FROM shared.outbox_messages ORDER BY id ASC');
        self::assertCount(2, $outboxRows); // 1: OrderSubmitted, 2: OrderCancelled

        /** @var OrderCancelledEvent $cancelledEvent */
        $cancelledEvent = unserialize(base64_decode((string) $outboxRows[1]['body'], true));
        self::assertInstanceOf(OrderCancelledEvent::class, $cancelledEvent);

        ($this->releaseConsumer)($cancelledEvent);
        $this->em->flush();
        $this->em->clear();

        // 4. Run reconciliation
        $this->commandBus->dispatch(new ReconcileStockCommand(50));
        $this->em->clear();

        $finalOrder = $this->orderProvider->findById($orderId);
        self::assertSame(OrderStatus::CANCELLED, $finalOrder->status());
        self::assertNull($finalOrder->nextCheckAt());
    }

    public function testBuyerCancelsConfirmedOrderAndStockIsReleased(): void
    {
        $prodId = CatalogFixtures::PRODUCT_KEYBOARD;
        $this->stockRepo->save(new StockPosition(new ProductId($prodId), onHand: 10, reserved: 0));
        $this->em->flush();
        $this->em->clear();

        $orderIdStr = '00000000-0000-4000-9100-000000000002';
        $buyerIdStr = 'buyer-cancel-2';
        $orderId = new OrderingOrderId($orderIdStr);

        $order = Order::createDraft($orderId, new BuyerId($buyerIdStr));
        $order->replaceItems(new OrderItemCollection([
            new OrderItem(new OrderingProductId($prodId), 3),
        ]));
        $this->orderRepo->save($order);
        $this->em->flush();
        $this->em->clear();

        // 1. Submit order
        $this->commandBus->dispatch(new SubmitOrderCommand($orderIdStr, $buyerIdStr));

        // 2. Consume reserve
        $conn = $this->em->getConnection();
        $body = $conn->fetchOne('SELECT body FROM shared.outbox_messages ORDER BY id ASC LIMIT 1');
        /** @var OrderSubmittedEvent $event */
        $event = unserialize(base64_decode((string) $body, true));
        ($this->reservationConsumer)($event);
        $this->em->flush();
        $this->em->clear();

        // 3. Reconcile to confirmed
        $this->commandBus->dispatch(new ReconcileStockCommand(50));
        $this->em->clear();

        $order = $this->orderProvider->findById($orderId);
        self::assertSame(OrderStatus::CONFIRMED, $order->status());

        // Check reserved stock in Inventory
        $stockPos = $this->stockPositionProvider->findByProductId(new ProductId($prodId));
        self::assertSame(3, $stockPos->reserved());

        // 4. Buyer cancels confirmed order
        $this->commandBus->dispatch(new CancelOrderCommand($orderIdStr, $buyerIdStr));
        $this->em->clear();

        $order = $this->orderProvider->findById($orderId);
        self::assertSame(OrderStatus::CANCELLING, $order->status());

        // 5. Consume cancellation event
        $lastBody = $conn->fetchOne('SELECT body FROM shared.outbox_messages ORDER BY id DESC LIMIT 1');
        /** @var OrderCancelledEvent $cancelledEvent */
        $cancelledEvent = unserialize(base64_decode((string) $lastBody, true));
        self::assertInstanceOf(OrderCancelledEvent::class, $cancelledEvent);

        ($this->releaseConsumer)($cancelledEvent);
        $this->em->flush();
        $this->em->clear();

        // Check stock was released
        $stockPosAfterRelease = $this->stockPositionProvider->findByProductId(new ProductId($prodId));
        self::assertSame(0, $stockPosAfterRelease->reserved());

        // 6. Reconcile to cancelled
        $this->commandBus->dispatch(new ReconcileStockCommand(50));
        $this->em->clear();

        $finalOrder = $this->orderProvider->findById($orderId);
        self::assertSame(OrderStatus::CANCELLED, $finalOrder->status());
        self::assertNull($finalOrder->nextCheckAt());
    }

    public function testCancellationArrivingBeforeReserveCreatesDefensiveReleased(): void
    {
        $prodId = CatalogFixtures::PRODUCT_KEYBOARD;
        $this->stockRepo->save(new StockPosition(new ProductId($prodId), onHand: 10, reserved: 0));
        $this->em->flush();
        $this->em->clear();

        $orderIdStr = '00000000-0000-4000-9100-000000000003';
        $buyerIdStr = 'buyer-cancel-3';
        $orderId = new OrderingOrderId($orderIdStr);

        $order = Order::createDraft($orderId, new BuyerId($buyerIdStr));
        $order->replaceItems(new OrderItemCollection([
            new OrderItem(new OrderingProductId($prodId), 2),
        ]));
        $this->orderRepo->save($order);
        $this->em->flush();
        $this->em->clear();

        $this->commandBus->dispatch(new SubmitOrderCommand($orderIdStr, $buyerIdStr));
        $this->commandBus->dispatch(new CancelOrderCommand($orderIdStr, $buyerIdStr));

        $conn = $this->em->getConnection();
        $outboxRows = $conn->fetchAllAssociative('SELECT id, body FROM shared.outbox_messages ORDER BY id ASC');
        /** @var OrderSubmittedEvent $submitEvent */
        $submitEvent = unserialize(base64_decode((string) $outboxRows[0]['body'], true));
        /** @var OrderCancelledEvent $cancelledEvent */
        $cancelledEvent = unserialize(base64_decode((string) $outboxRows[1]['body'], true));

        // Release arrives BEFORE reserve!
        ($this->releaseConsumer)($cancelledEvent);
        $this->em->flush();
        $this->em->clear();

        // Late reserve arrives
        ($this->reservationConsumer)($submitEvent);
        $this->em->flush();
        $this->em->clear();

        // Stock position reserved was NOT incremented
        $stockPos = $this->stockPositionProvider->findByProductId(new ProductId($prodId));
        self::assertSame(0, $stockPos->reserved());

        // Reconcile
        $this->commandBus->dispatch(new ReconcileStockCommand(50));
        $this->em->clear();

        $finalOrder = $this->orderProvider->findById($orderId);
        self::assertSame(OrderStatus::CANCELLED, $finalOrder->status());
    }

    public function testConfirmedOrderTimeoutExpiresAndReconcileInitiatesCancel(): void
    {
        $prodId = CatalogFixtures::PRODUCT_KEYBOARD;
        $this->stockRepo->save(new StockPosition(new ProductId($prodId), onHand: 10, reserved: 0));
        $this->em->flush();
        $this->em->clear();

        $orderIdStr = '00000000-0000-4000-9100-000000000004';
        $buyerIdStr = 'buyer-cancel-4';
        $orderId = new OrderingOrderId($orderIdStr);

        $order = Order::createDraft($orderId, new BuyerId($buyerIdStr));
        $order->replaceItems(new OrderItemCollection([
            new OrderItem(new OrderingProductId($prodId), 1),
        ]));
        $this->orderRepo->save($order);
        $this->em->flush();
        $this->em->clear();

        // Submit & confirm
        $this->commandBus->dispatch(new SubmitOrderCommand($orderIdStr, $buyerIdStr));
        $conn = $this->em->getConnection();
        $body = $conn->fetchOne('SELECT body FROM shared.outbox_messages ORDER BY id ASC LIMIT 1');
        /** @var OrderSubmittedEvent $event */
        $event = unserialize(base64_decode((string) $body, true));
        ($this->reservationConsumer)($event);
        $this->em->flush();
        $this->em->clear();

        $this->commandBus->dispatch(new ReconcileStockCommand(50));
        $this->em->clear();

        $order = $this->orderProvider->findById($orderId);
        self::assertSame(OrderStatus::CONFIRMED, $order->status());

        // Backdate order updated_at past 15 minutes (900 seconds)
        $past = new \DateTimeImmutable('-950 seconds')->format('Y-m-d H:i:s.uP');
        $conn->executeStatement("UPDATE ordering.orders SET updated_at = '{$past}', next_check_at = '{$past}' WHERE id = '{$orderIdStr}'");
        $this->em->clear();

        // Reconciliation detects timeout -> dispatches CancelOrderCommand -> status becomes cancelling
        $this->commandBus->dispatch(new ReconcileStockCommand(50));
        $this->em->clear();

        $cancellingOrder = $this->orderProvider->findById($orderId);
        self::assertSame(OrderStatus::CANCELLING, $cancellingOrder->status());

        // Relay and consume cancel outbox
        $lastBody = $conn->fetchOne('SELECT body FROM shared.outbox_messages ORDER BY id DESC LIMIT 1');
        /** @var OrderCancelledEvent $cancelledEvent */
        $cancelledEvent = unserialize(base64_decode((string) $lastBody, true));
        ($this->releaseConsumer)($cancelledEvent);
        $this->em->flush();
        $this->em->clear();

        // Reconcile to cancelled
        $this->commandBus->dispatch(new ReconcileStockCommand(50));
        $this->em->clear();

        $finalOrder = $this->orderProvider->findById($orderId);
        self::assertSame(OrderStatus::CANCELLED, $finalOrder->status());
    }
}
