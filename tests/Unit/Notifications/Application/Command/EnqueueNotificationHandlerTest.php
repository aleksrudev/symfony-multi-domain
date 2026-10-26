<?php

declare(strict_types=1);

namespace Tests\Unit\Notifications\Application\Command;

use App\Notifications\Application\Command\EnqueueNotification\EnqueueNotificationCommand;
use App\Notifications\Application\Command\EnqueueNotification\EnqueueNotificationHandler;
use App\Notifications\Application\Command\SendNotification\SendNotificationCommand;
use App\Notifications\Domain\Action\CreateNotificationAction;
use App\Notifications\Domain\DataProvider\NotificationDataProviderInterface;
use App\Notifications\Domain\Entity\Notification;
use App\Notifications\Domain\Enum\NotificationType;
use App\Notifications\Domain\Repository\NotificationRepositoryInterface;
use App\Notifications\Domain\ValueObject\NotificationId;
use App\Shared\Application\Bus\Command\CommandBusInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(EnqueueNotificationHandler::class)]
final class EnqueueNotificationHandlerTest extends TestCase
{
    public function testHandlerCreatesNotificationAndDispatchesSend(): void
    {
        $notificationId = new NotificationId('00000000-0000-4000-8000-000000000001');
        $notification = Notification::createPending(
            id: $notificationId,
            orderId: 'order-1',
            type: NotificationType::ORDER_CONFIRMED,
            buyerId: 'buyer-1',
            payload: ['k' => 'v'],
        );

        $dataProvider = $this->createMock(NotificationDataProviderInterface::class);
        $dataProvider->expects(self::once())
            ->method('findByOrderIdAndType')
            ->willReturn(null);

        $repository = $this->createMock(NotificationRepositoryInterface::class);
        $createAction = new CreateNotificationAction($dataProvider, $repository);

        $commandBus = $this->createMock(CommandBusInterface::class);
        $commandBus->expects(self::once())
            ->method('dispatch')
            ->with(self::callback(static fn (object $cmd): bool => $cmd instanceof SendNotificationCommand));

        $handler = new EnqueueNotificationHandler($createAction, $commandBus);
        $handler(new EnqueueNotificationCommand(
            orderId: 'order-1',
            type: 'order_confirmed',
            buyerId: 'buyer-1',
            payload: ['k' => 'v'],
        ));
    }
}
