<?php

declare(strict_types=1);

namespace Tests\Integration\Notifications;

use App\Notifications\Application\Command\SendNotification\SendNotificationCommand;
use App\Notifications\Domain\DataProvider\NotificationDataProviderInterface;
use App\Notifications\Domain\Entity\Notification;
use App\Notifications\Domain\Enum\NotificationStatus;
use App\Notifications\Domain\Enum\NotificationType;
use App\Notifications\Domain\Repository\NotificationRepositoryInterface;
use App\Notifications\Domain\ValueObject\NotificationId;
use App\Notifications\Infrastructure\Delivery\LocalMailboxDeliveryChannel;
use App\Notifications\Presentation\Contracts\Dto\EnqueueNotificationInput;
use App\Notifications\Presentation\Contracts\NotificationsContractInterface;
use App\Shared\Application\Bus\Command\CommandBusInterface;
use Tests\Integration\IntegrationTestCase;

final class NotificationDeliveryTest extends IntegrationTestCase
{
    private NotificationsContractInterface $contract;
    private NotificationDataProviderInterface $dataProvider;
    private NotificationRepositoryInterface $repository;
    private CommandBusInterface $commandBus;

    protected function setUp(): void
    {
        parent::setUp();
        $container = self::getContainer();
        $this->contract = $container->get(NotificationsContractInterface::class);
        $this->dataProvider = $container->get(NotificationDataProviderInterface::class);
        $this->repository = $container->get(NotificationRepositoryInterface::class);
        $this->commandBus = $container->get(CommandBusInterface::class);

        $conn = $this->em->getConnection();
        $conn->executeStatement("DELETE FROM notifications.notifications WHERE order_id LIKE '00000000-0000-4000-8800-%'");
    }

    public function testEnqueueNotificationDeliversSuccessfully(): void
    {
        $orderId = '00000000-0000-4000-8800-000000000001';

        $this->contract->enqueueNotification(new EnqueueNotificationInput(
            orderId: $orderId,
            type: 'order_confirmed',
            buyerId: 'buyer-1',
            payload: ['total' => 5000],
        ));

        $this->em->clear();

        $status = $this->contract->getNotificationStatus($orderId, 'order_confirmed');
        self::assertNotNull($status);
        self::assertSame('sent', $status->status);
        self::assertSame(1, $status->attempts);
        self::assertNotNull($status->sentAt);
        self::assertNull($status->errorMessage);

        // Idempotent repeat: does not create duplicate
        $this->contract->enqueueNotification(new EnqueueNotificationInput(
            orderId: $orderId,
            type: 'order_confirmed',
            buyerId: 'buyer-1',
            payload: ['total' => 5000],
        ));

        $this->em->clear();
        $all = $this->contract->getNotificationsForOrder($orderId);
        self::assertCount(1, $all);
    }

    public function testDeliveryFailureIncrementsAttemptsAndTransitionsToFailed(): void
    {
        $orderId = '00000000-0000-4000-8800-000000000002';
        $notifId = new NotificationId('00000000-0000-4000-8800-000000000002');

        // Create notification directly with failing channel
        $notification = Notification::createPending(
            id: $notifId,
            orderId: $orderId,
            type: NotificationType::ORDER_REJECTED,
            buyerId: 'buyer-1',
            payload: ['reason' => 'insufficient_stock'],
        );
        $this->repository->save($notification);
        $this->em->flush();
        $this->em->clear();

        // Use test container service with failDelivery: true
        $container = self::getContainer();
        // Replace delivery channel in container with failing channel
        $container->set(LocalMailboxDeliveryChannel::class, new LocalMailboxDeliveryChannel(failDelivery: true));

        // Attempt 1
        $this->commandBus->dispatch(new SendNotificationCommand($notifId->value));
        $this->em->clear();

        $notif1 = $this->dataProvider->findById($notifId);
        self::assertSame(NotificationStatus::PENDING, $notif1->status());
        self::assertSame(1, $notif1->attempts());
        self::assertNotNull($notif1->errorMessage());

        // Attempt 2
        $this->commandBus->dispatch(new SendNotificationCommand($notifId->value));
        $this->em->clear();

        $notif2 = $this->dataProvider->findById($notifId);
        self::assertSame(NotificationStatus::PENDING, $notif2->status());
        self::assertSame(2, $notif2->attempts());

        // Attempt 3: transitions to failed
        $this->commandBus->dispatch(new SendNotificationCommand($notifId->value));
        $this->em->clear();

        $notif3 = $this->dataProvider->findById($notifId);
        self::assertSame(NotificationStatus::FAILED, $notif3->status());
        self::assertSame(3, $notif3->attempts());

        // Verified via public contract
        $statusDto = $this->contract->getNotificationStatus($orderId, 'order_rejected');
        self::assertNotNull($statusDto);
        self::assertSame('failed', $statusDto->status);
        self::assertSame(3, $statusDto->attempts);
    }
}
