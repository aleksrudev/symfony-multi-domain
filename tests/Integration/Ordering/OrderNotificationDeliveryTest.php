<?php

declare(strict_types=1);

namespace Tests\Integration\Ordering;

use App\Catalog\Infrastructure\Persistence\Doctrine\Fixtures\CatalogFixtures;
use App\Notifications\Infrastructure\Delivery\LocalMailboxDeliveryChannel;
use App\Notifications\Infrastructure\Integration\Ordering\OrderCancellationFinalizedNotificationConsumer;
use App\Notifications\Infrastructure\Integration\Ordering\OrderConfirmedNotificationConsumer;
use App\Notifications\Infrastructure\Integration\Ordering\OrderRejectedNotificationConsumer;
use App\Notifications\Presentation\Contracts\NotificationsContractInterface;
use App\Ordering\Application\Command\ConfirmOrder\ConfirmOrderCommand;
use App\Ordering\Application\Command\FinalizeOrderCancellation\FinalizeOrderCancellationCommand;
use App\Ordering\Application\Command\RejectOrder\RejectOrderCommand;
use App\Ordering\Domain\DataProvider\OrderDataProviderInterface;
use App\Ordering\Domain\Entity\Order;
use App\Ordering\Domain\Enum\Currency;
use App\Ordering\Domain\Enum\OrderStatus;
use App\Ordering\Domain\Repository\OrderRepositoryInterface;
use App\Ordering\Domain\ValueObject\BuyerId;
use App\Ordering\Domain\ValueObject\OrderId;
use App\Ordering\Presentation\Contracts\Event\OrderCancellationFinalizedEvent;
use App\Ordering\Presentation\Contracts\Event\OrderConfirmedEvent;
use App\Ordering\Presentation\Contracts\Event\OrderRejectedEvent;
use App\Shared\Application\Bus\Command\CommandBusInterface;
use App\Shared\Infrastructure\Messaging\Outbox\Relay\OutboxRelay;
use Tests\Integration\IntegrationTestCase;

final class OrderNotificationDeliveryTest extends IntegrationTestCase
{
    private CommandBusInterface $commandBus;
    private OrderRepositoryInterface $orderRepo;
    private OrderDataProviderInterface $orderProvider;
    private NotificationsContractInterface $notificationsContract;
    private OutboxRelay $outboxRelay;
    private OrderConfirmedNotificationConsumer $confirmedConsumer;
    private OrderRejectedNotificationConsumer $rejectedConsumer;
    private OrderCancellationFinalizedNotificationConsumer $cancelledConsumer;

    protected function setUp(): void
    {
        parent::setUp();
        $container = self::getContainer();
        $this->commandBus = $container->get(CommandBusInterface::class);
        $this->orderRepo = $container->get(OrderRepositoryInterface::class);
        $this->orderProvider = $container->get(OrderDataProviderInterface::class);
        $this->notificationsContract = $container->get(NotificationsContractInterface::class);
        $this->outboxRelay = $container->get(OutboxRelay::class);
        $this->confirmedConsumer = $container->get(OrderConfirmedNotificationConsumer::class);
        $this->rejectedConsumer = $container->get(OrderRejectedNotificationConsumer::class);
        $this->cancelledConsumer = $container->get(OrderCancellationFinalizedNotificationConsumer::class);

        $fixtures = new CatalogFixtures();
        $fixtures->load($this->em);

        $conn = $this->em->getConnection();
        $conn->executeStatement("DELETE FROM ordering.order_items WHERE order_id LIKE '00000000-0000-4000-8500-%'");
        $conn->executeStatement("DELETE FROM ordering.orders WHERE id LIKE '00000000-0000-4000-8500-%'");
        $conn->executeStatement("DELETE FROM notifications.notifications WHERE order_id LIKE '00000000-0000-4000-8500-%'");
        $conn->executeStatement('DELETE FROM shared.outbox_messages');
        $conn->executeStatement('DELETE FROM shared.processed_messages');
        $conn->executeStatement("DELETE FROM public.messenger_messages WHERE queue_name = 'async'");
    }

    public function testOrderConfirmationPublishesIntegrationEventAndDeliversToNotifications(): void
    {
        $orderIdStr = '00000000-0000-4000-8500-000000000001';
        $buyerIdStr = 'buyer-notif-1';
        $orderId = new OrderId($orderIdStr);

        $order = new Order(
            $orderId,
            new BuyerId($buyerIdStr),
            OrderStatus::PENDING_STOCK,
            totalAmount: 12000,
            currency: Currency::RUB,
        );
        $this->orderRepo->save($order);
        $this->em->flush();
        $this->em->clear();

        // 1. Confirm order
        $this->commandBus->dispatch(new ConfirmOrderCommand($orderIdStr));
        $this->em->clear();

        $order = $this->orderProvider->findById($orderId);
        self::assertSame(OrderStatus::CONFIRMED, $order->status());

        // 2. Check outbox has public OrderConfirmedEvent
        $conn = $this->em->getConnection();
        $body = $conn->fetchOne('SELECT body FROM shared.outbox_messages ORDER BY id DESC LIMIT 1');
        /** @var OrderConfirmedEvent $event */
        $event = unserialize(base64_decode((string) $body, true));
        self::assertInstanceOf(OrderConfirmedEvent::class, $event);
        self::assertSame('ordering.order_confirmed', $event->type);
        self::assertSame($orderIdStr, $event->orderId);
        self::assertSame($buyerIdStr, $event->buyerId);
        self::assertSame(12000, $event->totalAmount);

        // 3. Relay outbox to async transport
        $relayed = $this->outboxRelay->relayOne();
        self::assertTrue($relayed);
        self::assertSame(1, (int) $conn->fetchOne("SELECT COUNT(*) FROM public.messenger_messages WHERE queue_name = 'async'"));

        // 4. Notifications consumer handles event
        ($this->confirmedConsumer)($event);
        $this->em->flush();
        $this->em->clear();

        // 5. Verify in Notifications
        $status = $this->notificationsContract->getNotificationStatus($orderIdStr, 'order_confirmed');
        self::assertNotNull($status);
        self::assertSame('sent', $status->status);
        self::assertSame(1, $status->attempts);

        // 6. Duplicate event does not create duplicate notification (IdempotentConsumer)
        ($this->confirmedConsumer)($event);
        $this->em->flush();
        $this->em->clear();

        $notifications = $this->notificationsContract->getNotificationsForOrder($orderIdStr);
        self::assertCount(1, $notifications);
    }

    public function testOrderRejectionPublishesIntegrationEventAndDeliversToNotifications(): void
    {
        $orderIdStr = '00000000-0000-4000-8500-000000000002';
        $buyerIdStr = 'buyer-notif-2';
        $orderId = new OrderId($orderIdStr);

        $order = new Order(
            $orderId,
            new BuyerId($buyerIdStr),
            OrderStatus::PENDING_STOCK,
        );
        $this->orderRepo->save($order);
        $this->em->flush();
        $this->em->clear();

        // 1. Reject order
        $this->commandBus->dispatch(new RejectOrderCommand($orderIdStr, 'insufficient_stock'));
        $this->em->clear();

        $conn = $this->em->getConnection();
        $body = $conn->fetchOne('SELECT body FROM shared.outbox_messages ORDER BY id DESC LIMIT 1');
        /** @var OrderRejectedEvent $event */
        $event = unserialize(base64_decode((string) $body, true));
        self::assertInstanceOf(OrderRejectedEvent::class, $event);
        self::assertSame('ordering.order_rejected', $event->type);
        self::assertSame($orderIdStr, $event->orderId);
        self::assertSame('insufficient_stock', $event->reason);

        // 2. Relay & deliver via Notifications consumer
        $this->outboxRelay->relayOne();
        ($this->rejectedConsumer)($event);
        $this->em->flush();
        $this->em->clear();

        $status = $this->notificationsContract->getNotificationStatus($orderIdStr, 'order_rejected');
        self::assertNotNull($status);
        self::assertSame('sent', $status->status);
    }

    public function testOrderCancellationFinalizedPublishesIntegrationEventAndDeliversToNotifications(): void
    {
        $orderIdStr = '00000000-0000-4000-8500-000000000003';
        $buyerIdStr = 'buyer-notif-3';
        $orderId = new OrderId($orderIdStr);

        $order = new Order(
            $orderId,
            new BuyerId($buyerIdStr),
            OrderStatus::CANCELLING,
        );
        $this->orderRepo->save($order);
        $this->em->flush();
        $this->em->clear();

        // 1. Finalize cancellation
        $this->commandBus->dispatch(new FinalizeOrderCancellationCommand($orderIdStr));
        $this->em->clear();

        $conn = $this->em->getConnection();
        $body = $conn->fetchOne('SELECT body FROM shared.outbox_messages ORDER BY id DESC LIMIT 1');
        /** @var OrderCancellationFinalizedEvent $event */
        $event = unserialize(base64_decode((string) $body, true));
        self::assertInstanceOf(OrderCancellationFinalizedEvent::class, $event);
        self::assertSame('ordering.order_cancellation_finalized', $event->type);
        self::assertSame($orderIdStr, $event->orderId);

        // 2. Deliver via Notifications consumer
        $this->outboxRelay->relayOne();
        ($this->cancelledConsumer)($event);
        $this->em->flush();
        $this->em->clear();

        $status = $this->notificationsContract->getNotificationStatus($orderIdStr, 'order_cancelled');
        self::assertNotNull($status);
        self::assertSame('sent', $status->status);
    }

    public function testNotificationDeliveryFailureDoesNotAffectOrderStatus(): void
    {
        $orderIdStr = '00000000-0000-4000-8500-000000000004';
        $buyerIdStr = 'buyer-notif-4';
        $orderId = new OrderId($orderIdStr);

        $order = new Order(
            $orderId,
            new BuyerId($buyerIdStr),
            OrderStatus::PENDING_STOCK,
            totalAmount: 10000,
            currency: Currency::RUB,
        );
        $this->orderRepo->save($order);
        $this->em->flush();
        $this->em->clear();

        // 1. Confirm order
        $this->commandBus->dispatch(new ConfirmOrderCommand($orderIdStr));
        $this->em->clear();

        $order = $this->orderProvider->findById($orderId);
        self::assertSame(OrderStatus::CONFIRMED, $order->status());

        // 2. Outbox has event
        $conn = $this->em->getConnection();
        $body = $conn->fetchOne('SELECT body FROM shared.outbox_messages ORDER BY id DESC LIMIT 1');
        /** @var OrderConfirmedEvent $event */
        $event = unserialize(base64_decode((string) $body, true));

        // 3. Simulate failure in Notifications delivery
        LocalMailboxDeliveryChannel::setOverrideFailDelivery(true);
        try {
            ($this->confirmedConsumer)($event);
        } finally {
            LocalMailboxDeliveryChannel::setOverrideFailDelivery(null);
        }

        // 4. Order status must still be CONFIRMED!
        $this->em->clear();
        $orderAfterFailure = $this->orderProvider->findById($orderId);
        self::assertSame(OrderStatus::CONFIRMED, $orderAfterFailure->status());

        // 5. Notification in Notifications is in pending state with recorded failure
        $status = $this->notificationsContract->getNotificationStatus($orderIdStr, 'order_confirmed');
        self::assertNotNull($status);
        self::assertSame('pending', $status->status);
        self::assertSame(1, $status->attempts);
        self::assertNotNull($status->errorMessage);
    }
}
