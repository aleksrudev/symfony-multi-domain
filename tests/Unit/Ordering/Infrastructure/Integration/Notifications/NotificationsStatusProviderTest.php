<?php

declare(strict_types=1);

namespace Tests\Unit\Ordering\Infrastructure\Integration\Notifications;

use App\Notifications\Presentation\Contracts\Dto\NotificationStatusDto;
use App\Notifications\Presentation\Contracts\NotificationsContractInterface;
use App\Ordering\Infrastructure\Integration\Notifications\NotificationsStatusProvider;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(NotificationsStatusProvider::class)]
final class NotificationsStatusProviderTest extends TestCase
{
    public function testGetNotificationsForOrderAdaptsContractDtos(): void
    {
        $orderId = '00000000-0000-4000-8000-000000000001';

        $contract = $this->createMock(NotificationsContractInterface::class);
        $contract->expects(self::once())
            ->method('getNotificationsForOrder')
            ->with($orderId)
            ->willReturn([
                new NotificationStatusDto(
                    id: 'notif-1',
                    orderId: $orderId,
                    type: 'order_confirmed',
                    buyerId: 'buyer-1',
                    status: 'sent',
                    attempts: 1,
                    payload: ['total' => 1000],
                    errorMessage: null,
                    createdAt: '2026-10-27T10:00:00.000000Z',
                    updatedAt: '2026-10-27T10:00:01.000000Z',
                    sentAt: '2026-10-27T10:00:01.000000Z',
                ),
            ]);

        $provider = new NotificationsStatusProvider($contract);
        $notifications = $provider->getNotificationsForOrder($orderId);

        self::assertCount(1, $notifications);
        self::assertSame('notif-1', $notifications[0]->id);
        self::assertSame('order_confirmed', $notifications[0]->type);
        self::assertSame('sent', $notifications[0]->status);
        self::assertSame(1, $notifications[0]->attempts);
        self::assertNull($notifications[0]->errorMessage);
        self::assertSame('2026-10-27T10:00:00.000000Z', $notifications[0]->createdAt);
        self::assertSame('2026-10-27T10:00:01.000000Z', $notifications[0]->updatedAt);
        self::assertSame('2026-10-27T10:00:01.000000Z', $notifications[0]->sentAt);
    }
}

