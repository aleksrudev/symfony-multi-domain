<?php

declare(strict_types=1);

namespace Tests\Unit\Notifications\Domain\Action;

use App\Notifications\Domain\Action\CreateNotificationAction;
use App\Notifications\Domain\DataProvider\NotificationDataProviderInterface;
use App\Notifications\Domain\Entity\Notification;
use App\Notifications\Domain\Enum\NotificationType;
use App\Notifications\Domain\Repository\NotificationRepositoryInterface;
use App\Notifications\Domain\ValueObject\NotificationId;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(CreateNotificationAction::class)]
final class CreateNotificationActionTest extends TestCase
{
    public function testCreatesNewNotificationWhenNoneExists(): void
    {
        $dataProvider = $this->createMock(NotificationDataProviderInterface::class);
        $dataProvider->expects(self::once())
            ->method('findByOrderIdAndType')
            ->with('order-1', NotificationType::ORDER_CONFIRMED)
            ->willReturn(null);

        $repository = $this->createMock(NotificationRepositoryInterface::class);
        $repository->expects(self::once())
            ->method('save')
            ->with(self::callback(static fn (Notification $n): bool => 'order-1' === $n->orderId()
                && NotificationType::ORDER_CONFIRMED === $n->type()
                && 'buyer-1' === $n->buyerId()));

        $action = new CreateNotificationAction($dataProvider, $repository);
        $result = $action('order-1', NotificationType::ORDER_CONFIRMED, 'buyer-1', ['key' => 'val']);

        self::assertSame('order-1', $result->orderId());
        self::assertSame('buyer-1', $result->buyerId());
    }

    public function testReturnsExistingNotificationWhenAlreadyExists(): void
    {
        $existing = Notification::createPending(
            id: new NotificationId(),
            orderId: 'order-1',
            type: NotificationType::ORDER_CONFIRMED,
            buyerId: 'buyer-1',
            payload: ['key' => 'val'],
        );

        $dataProvider = $this->createMock(NotificationDataProviderInterface::class);
        $dataProvider->expects(self::once())
            ->method('findByOrderIdAndType')
            ->with('order-1', NotificationType::ORDER_CONFIRMED)
            ->willReturn($existing);

        $repository = $this->createMock(NotificationRepositoryInterface::class);
        $repository->expects(self::never())->method('save');

        $action = new CreateNotificationAction($dataProvider, $repository);
        $result = $action('order-1', NotificationType::ORDER_CONFIRMED, 'buyer-1', ['key' => 'val']);

        self::assertSame($existing, $result);
    }
}
