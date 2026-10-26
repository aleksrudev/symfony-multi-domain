<?php

declare(strict_types=1);

namespace Tests\Integration\Ordering;

use App\Catalog\Infrastructure\Persistence\Doctrine\Fixtures\CatalogFixtures;
use App\Notifications\Infrastructure\Delivery\LocalMailboxDeliveryChannel;
use App\Notifications\Presentation\Contracts\NotificationsContractInterface;
use App\Ordering\Application\Command\ConfirmOrder\ConfirmOrderCommand;
use App\Ordering\Application\Command\FinalizeOrderCancellation\FinalizeOrderCancellationCommand;
use App\Ordering\Application\Command\RejectOrder\RejectOrderCommand;
use App\Ordering\Domain\DataProvider\OrderDataProviderInterface;
use App\Ordering\Domain\Entity\Order;
use App\Ordering\Domain\Enum\OrderStatus;
use App\Ordering\Domain\Repository\OrderRepositoryInterface;
use App\Ordering\Domain\ValueObject\BuyerId;
use App\Ordering\Domain\ValueObject\OrderId;
use App\Ordering\Infrastructure\Integration\Notifications\OrderNotificationDeliveryHandler;
use App\Ordering\Infrastructure\Messaging\Outbox\OrderNotification\OrderNotificationMessage;
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
    private OrderNotificationDeliveryHandler $deliveryHandler;

    protected function setUp(): void
    {
        parent::setUp();
        $container = self::getContainer();
        $this->commandBus = $container->get(CommandBusInterface::class);
        $this->orderRepo = $container->get(OrderRepositoryInterface::class);
        $this->orderProvider = $container->get(OrderDataProviderInterface::class);
        $this->notificationsContract = $container->get(NotificationsContractInterface::class);
        $this->outboxRelay = $container->get(OutboxRelay::class);
        $this->deliveryHandler = $container->get(OrderNotificationDeliveryHandler::class);

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

    public function testOrderConfirmationPublishesNotificationAndDeliversToMailbox(): void
    {
        $orderIdStr = '00000000-0000-4000-8500-000000000001';
        $buyerIdStr = 'buyer-notif-1';
        $orderId = new OrderId($orderIdStr);

        $order = new Order(
            $orderId,
            new BuyerId($buyerIdStr),
            OrderStatus::PENDING_STOCK,
            totalAmount: 12000,
        );
        $this->orderRepo->save($order);
        $this->em->flush();
        $this->em->clear();

        // 1. Confirm order
        $this->commandBus->dispatch(new ConfirmOrderCommand($orderIdStr));
        $this->em->clear();

        $order = $this->orderProvider->findById($orderId);
        self::assertSame(OrderStatus::CONFIRMED, $order->status());

        // 2. Check outbox has OrderNotificationMessage
        $conn = $this->em->getConnection();
        $body = $conn->fetchOne('SELECT body FROM shared.outbox_messages ORDER BY id DESC LIMIT 1');
        /** @var OrderNotificationMessage $msg */
        $msg = unserialize(base64_decode((string) $body, true));
        self::assertInstanceOf(OrderNotificationMessage::class, $msg);
        self::assertSame('order_confirmed', $msg->type);
        self::assertSame($orderIdStr, $msg->orderId);

        // 3. Relay outbox and deliver
        $this->outboxRelay->relayOne();
        ($this->deliveryHandler)($msg);
        $this->em->flush();
        $this->em->clear();

        // 4. Verify in Notifications
        $status = $this->notificationsContract->getNotificationStatus($orderIdStr, 'order_confirmed');
        self::assertNotNull($status);
        self::assertSame('sent', $status->status);
        self::assertSame(1, $status->attempts);
    }

    public function testOrderRejectionPublishesNotificationAndDelivers(): void
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

        // Reject
        $this->commandBus->dispatch(new RejectOrderCommand($orderIdStr, 'insufficient_stock'));
        $this->em->clear();

        $conn = $this->em->getConnection();
        $body = $conn->fetchOne('SELECT body FROM shared.outbox_messages ORDER BY id DESC LIMIT 1');
        /** @var OrderNotificationMessage $msg */
        $msg = unserialize(base64_decode((string) $body, true));
        self::assertInstanceOf(OrderNotificationMessage::class, $msg);
        self::assertSame('order_rejected', $msg->type);
        self::assertSame('insufficient_stock', $msg->payload['reason'] ?? null);

        // Relay & deliver
        ($this->deliveryHandler)($msg);
        $this->em->flush();
        $this->em->clear();

        $status = $this->notificationsContract->getNotificationStatus($orderIdStr, 'order_rejected');
        self::assertNotNull($status);
        self::assertSame('sent', $status->status);
    }

    public function testOrderCancellationFinalizedPublishesNotificationAndDelivers(): void
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

        // Finalize cancellation
        $this->commandBus->dispatch(new FinalizeOrderCancellationCommand($orderIdStr));
        $this->em->clear();

        $conn = $this->em->getConnection();
        $body = $conn->fetchOne('SELECT body FROM shared.outbox_messages ORDER BY id DESC LIMIT 1');
        /** @var OrderNotificationMessage $msg */
        $msg = unserialize(base64_decode((string) $body, true));
        self::assertInstanceOf(OrderNotificationMessage::class, $msg);
        self::assertSame('order_cancelled', $msg->type);

        // Deliver
        ($this->deliveryHandler)($msg);
        $this->em->flush();
        $this->em->clear();

        $status = $this->notificationsContract->getNotificationStatus($orderIdStr, 'order_cancelled');
        self::assertNotNull($status);
        self::assertSame('sent', $status->status);
    }

    public function testDeliveryFailureDoesNotAffectOrderStatus(): void
    {
        $orderIdStr = '00000000-0000-4000-8500-000000000004';
        $buyerIdStr = 'buyer-notif-4';
        $orderId = new OrderId($orderIdStr);

        $order = new Order(
            $orderId,
            new BuyerId($buyerIdStr),
            OrderStatus::PENDING_STOCK,
            totalAmount: 10000,
        );
        $this->orderRepo->save($order);
        $this->em->flush();
        $this->em->clear();

        // 1. Confirm order
        $this->commandBus->dispatch(new ConfirmOrderCommand($orderIdStr));
        $this->em->clear();

        $order = $this->orderProvider->findById($orderId);
        self::assertSame(OrderStatus::CONFIRMED, $order->status());

        // 2. Set failDelivery = true
        $container = self::getContainer();
        $container->set(LocalMailboxDeliveryChannel::class, new LocalMailboxDeliveryChannel(failDelivery: true));

        // 3. Deliver notification (it fails inside Notifications)
        $conn = $this->em->getConnection();
        $body = $conn->fetchOne('SELECT body FROM shared.outbox_messages ORDER BY id DESC LIMIT 1');
        /** @var OrderNotificationMessage $msg */
        $msg = unserialize(base64_decode((string) $body, true));

        ($this->deliveryHandler)($msg);
        $this->em->flush();
        $this->em->clear();

        // Order remains confirmed!
        $orderAfterFailure = $this->orderProvider->findById($orderId);
        self::assertSame(OrderStatus::CONFIRMED, $orderAfterFailure->status());

        // Notification status is pending with error recorded
        $status = $this->notificationsContract->getNotificationStatus($orderIdStr, 'order_confirmed');
        self::assertNotNull($status);
        self::assertSame('pending', $status->status);
        self::assertSame(1, $status->attempts);
        self::assertNotNull($status->errorMessage);
    }
}
