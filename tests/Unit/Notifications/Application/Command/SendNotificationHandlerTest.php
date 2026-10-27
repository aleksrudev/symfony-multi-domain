<?php

declare(strict_types=1);

namespace Tests\Unit\Notifications\Application\Command;

use App\Notifications\Application\Command\SendNotification\Provider\NotificationDeliveryChannelInterface;
use App\Notifications\Application\Command\SendNotification\Provider\NotificationDeliveryFailedException;
use App\Notifications\Application\Command\SendNotification\SendNotificationCommand;
use App\Notifications\Application\Command\SendNotification\SendNotificationHandler;
use App\Notifications\Domain\Action\MarkNotificationSentAction;
use App\Notifications\Domain\Action\RecordNotificationFailureAction;
use App\Notifications\Domain\DataProvider\NotificationDataProviderInterface;
use App\Notifications\Domain\Entity\Notification;
use App\Notifications\Domain\Enum\NotificationStatus;
use App\Notifications\Domain\Enum\NotificationType;
use App\Notifications\Domain\Repository\NotificationRepositoryInterface;
use App\Notifications\Domain\ValueObject\NotificationId;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(SendNotificationHandler::class)]
final class SendNotificationHandlerTest extends TestCase
{
    public function testSendNotificationMarksSentOnSuccess(): void
    {
        $notificationId = new NotificationId('00000000-0000-4000-8000-000000000002');
        $notification = Notification::createPending(
            id: $notificationId,
            orderId: 'order-2',
            type: NotificationType::ORDER_CONFIRMED,
            buyerId: 'buyer-2',
            payload: [],
        );

        $dataProvider = $this->createMock(NotificationDataProviderInterface::class);
        $dataProvider->expects(self::once())
            ->method('findById')
            ->with($notificationId, true)
            ->willReturn($notification);

        $channel = $this->createMock(NotificationDeliveryChannelInterface::class);
        $channel->expects(self::once())
            ->method('send')
            ->with($notification);

        $repository = $this->createMock(NotificationRepositoryInterface::class);
        $markSentAction = new MarkNotificationSentAction($repository);
        $recordFailureAction = new RecordNotificationFailureAction($repository);

        $handler = new SendNotificationHandler($dataProvider, $channel, $markSentAction, $recordFailureAction);
        $handler(new SendNotificationCommand($notificationId->value));

        self::assertSame(NotificationStatus::SENT, $notification->status());
        self::assertSame(1, $notification->attempts());
    }

    public function testSendNotificationRecordsFailureOnError(): void
    {
        $notificationId = new NotificationId('00000000-0000-4000-8000-000000000003');
        $notification = Notification::createPending(
            id: $notificationId,
            orderId: 'order-3',
            type: NotificationType::ORDER_CONFIRMED,
            buyerId: 'buyer-3',
            payload: [],
        );

        $dataProvider = $this->createMock(NotificationDataProviderInterface::class);
        $dataProvider->expects(self::once())
            ->method('findById')
            ->with($notificationId, true)
            ->willReturn($notification);

        $channel = $this->createMock(NotificationDeliveryChannelInterface::class);
        $channel->expects(self::once())
            ->method('send')
            ->willThrowException(new NotificationDeliveryFailedException('Network down'));

        $repository = $this->createMock(NotificationRepositoryInterface::class);
        $markSentAction = new MarkNotificationSentAction($repository);
        $recordFailureAction = new RecordNotificationFailureAction($repository);

        $handler = new SendNotificationHandler($dataProvider, $channel, $markSentAction, $recordFailureAction, 3);
        $handler(new SendNotificationCommand($notificationId->value));

        self::assertSame(NotificationStatus::PENDING, $notification->status());
        self::assertSame(1, $notification->attempts());
        self::assertSame('Network down', $notification->errorMessage());
    }

    public function testSendNotificationSkipsIfAlreadySent(): void
    {
        $notificationId = new NotificationId('00000000-0000-4000-8000-000000000004');
        $notification = new Notification(
            id: $notificationId,
            orderId: 'order-4',
            type: NotificationType::ORDER_CONFIRMED,
            buyerId: 'buyer-4',
            payload: [],
            status: NotificationStatus::SENT,
        );

        $dataProvider = $this->createMock(NotificationDataProviderInterface::class);
        $dataProvider->expects(self::once())
            ->method('findById')
            ->willReturn($notification);

        $channel = $this->createMock(NotificationDeliveryChannelInterface::class);
        $channel->expects(self::never())->method('send');

        $repository = $this->createMock(NotificationRepositoryInterface::class);
        $markSentAction = new MarkNotificationSentAction($repository);
        $recordFailureAction = new RecordNotificationFailureAction($repository);

        $handler = new SendNotificationHandler($dataProvider, $channel, $markSentAction, $recordFailureAction);
        $handler(new SendNotificationCommand($notificationId->value));
    }
}
