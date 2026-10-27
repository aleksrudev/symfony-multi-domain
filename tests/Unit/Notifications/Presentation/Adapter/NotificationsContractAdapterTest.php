<?php

declare(strict_types=1);

namespace Tests\Unit\Notifications\Presentation\Adapter;

use App\Notifications\Application\Command\EnqueueNotification\EnqueueNotificationCommand;
use App\Notifications\Application\Query\GetNotificationStatus\GetNotificationStatusQuery;
use App\Notifications\Application\Query\GetNotificationStatus\Result\NotificationStatusQueryResult;
use App\Notifications\Domain\Collection\NotificationCollection;
use App\Notifications\Domain\DataProvider\NotificationDataProviderInterface;
use App\Notifications\Domain\Entity\Notification;
use App\Notifications\Domain\Enum\NotificationType;
use App\Notifications\Domain\ValueObject\NotificationId;
use App\Notifications\Presentation\Adapter\NotificationsContractAdapter;
use App\Notifications\Presentation\Contracts\Dto\EnqueueNotificationInput;
use App\Shared\Application\Bus\Command\CommandBusInterface;
use App\Shared\Application\Bus\Query\QueryBusInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(NotificationsContractAdapter::class)]
final class NotificationsContractAdapterTest extends TestCase
{
    public function testEnqueueNotificationDispatchesCommand(): void
    {
        $commandBus = $this->createMock(CommandBusInterface::class);
        $commandBus->expects(self::once())
            ->method('dispatch')
            ->with(self::callback(static fn (object $cmd): bool => $cmd instanceof EnqueueNotificationCommand
                && 'order-1' === $cmd->orderId
                && 'order_confirmed' === $cmd->type));

        $queryBus = $this->createMock(QueryBusInterface::class);
        $dataProvider = $this->createMock(NotificationDataProviderInterface::class);

        $adapter = new NotificationsContractAdapter($commandBus, $queryBus, $dataProvider);
        $adapter->enqueueNotification(new EnqueueNotificationInput(
            orderId: 'order-1',
            type: 'order_confirmed',
            buyerId: 'buyer-1',
        ));
    }

    public function testGetNotificationStatusDispatchesQuery(): void
    {
        $commandBus = $this->createMock(CommandBusInterface::class);
        $queryBus = $this->createMock(QueryBusInterface::class);
        $queryBus->expects(self::once())
            ->method('dispatch')
            ->with(self::callback(static fn (object $q): bool => $q instanceof GetNotificationStatusQuery
                && 'order-1' === $q->orderId
                && 'order_confirmed' === $q->type))
            ->willReturn(new NotificationStatusQueryResult(
                id: 'notif-1',
                orderId: 'order-1',
                type: 'order_confirmed',
                buyerId: 'buyer-1',
                status: 'sent',
                attempts: 1,
                payload: [],
                errorMessage: null,
                createdAt: '2026-10-26T12:00:00+00:00',
                updatedAt: '2026-10-26T12:00:01+00:00',
                sentAt: '2026-10-26T12:00:01+00:00',
            ));

        $dataProvider = $this->createMock(NotificationDataProviderInterface::class);

        $adapter = new NotificationsContractAdapter($commandBus, $queryBus, $dataProvider);
        $dto = $adapter->getNotificationStatus('order-1', 'order_confirmed');

        self::assertNotNull($dto);
        self::assertSame('notif-1', $dto->id);
        self::assertSame('sent', $dto->status);
    }

    public function testGetNotificationsForOrderQueriesDataProvider(): void
    {
        $commandBus = $this->createMock(CommandBusInterface::class);
        $queryBus = $this->createMock(QueryBusInterface::class);

        $notification = Notification::createPending(
            id: new NotificationId(),
            orderId: 'order-1',
            type: NotificationType::ORDER_CONFIRMED,
            buyerId: 'buyer-1',
            payload: [],
        );

        $dataProvider = $this->createMock(NotificationDataProviderInterface::class);
        $dataProvider->expects(self::once())
            ->method('findByOrderId')
            ->with('order-1')
            ->willReturn(new NotificationCollection([$notification]));

        $adapter = new NotificationsContractAdapter($commandBus, $queryBus, $dataProvider);
        $dtos = $adapter->getNotificationsForOrder('order-1');

        self::assertCount(1, $dtos);
        self::assertSame('order-1', $dtos[0]->orderId);
    }
}
