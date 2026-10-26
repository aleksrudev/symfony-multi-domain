<?php

declare(strict_types=1);

namespace Tests\Unit\Notifications\Domain;

use App\Notifications\Domain\Entity\Notification;
use App\Notifications\Domain\Enum\NotificationStatus;
use App\Notifications\Domain\Enum\NotificationType;
use App\Notifications\Domain\ValueObject\NotificationId;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Notification::class)]
#[CoversClass(NotificationId::class)]
final class NotificationTest extends TestCase
{
    public function testCreatePendingNotification(): void
    {
        $id = new NotificationId('00000000-0000-4000-8000-000000000001');
        $notification = Notification::createPending(
            id: $id,
            orderId: 'order-123',
            type: NotificationType::ORDER_CONFIRMED,
            buyerId: 'buyer-1',
            payload: ['amount' => 1000],
        );

        self::assertTrue($notification->id()->equals($id));
        self::assertSame('order-123', $notification->orderId());
        self::assertSame(NotificationType::ORDER_CONFIRMED, $notification->type());
        self::assertSame('buyer-1', $notification->buyerId());
        self::assertSame(['amount' => 1000], $notification->payload());
        self::assertSame(NotificationStatus::PENDING, $notification->status());
        self::assertSame(0, $notification->attempts());
        self::assertNull($notification->errorMessage());
        self::assertNull($notification->sentAt());
    }

    public function testMarkSent(): void
    {
        $notification = Notification::createPending(
            id: new NotificationId(),
            orderId: 'order-123',
            type: NotificationType::ORDER_CONFIRMED,
            buyerId: 'buyer-1',
            payload: [],
        );

        $sentAt = new \DateTimeImmutable('2026-10-26 12:00:00+00:00');
        $notification->markSent($sentAt);

        self::assertSame(NotificationStatus::SENT, $notification->status());
        self::assertSame(1, $notification->attempts());
        self::assertSame($sentAt, $notification->sentAt());
        self::assertNull($notification->errorMessage());
    }

    public function testRecordFailureTransitionsToFailedAtMaxAttempts(): void
    {
        $notification = Notification::createPending(
            id: new NotificationId(),
            orderId: 'order-123',
            type: NotificationType::ORDER_REJECTED,
            buyerId: 'buyer-1',
            payload: [],
        );

        // Attempt 1: still pending
        $notification->recordFailure('Connection timeout', 3);
        self::assertSame(NotificationStatus::PENDING, $notification->status());
        self::assertSame(1, $notification->attempts());
        self::assertSame('Connection timeout', $notification->errorMessage());

        // Attempt 2: still pending
        $notification->recordFailure('Connection timeout 2', 3);
        self::assertSame(NotificationStatus::PENDING, $notification->status());
        self::assertSame(2, $notification->attempts());

        // Attempt 3: transitions to failed
        $notification->recordFailure('Fatal failure', 3);
        self::assertSame(NotificationStatus::FAILED, $notification->status());
        self::assertSame(3, $notification->attempts());
        self::assertSame('Fatal failure', $notification->errorMessage());
    }
}
