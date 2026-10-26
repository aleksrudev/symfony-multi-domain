<?php

declare(strict_types=1);

namespace Tests\Unit\Ordering\Infrastructure\Messaging\EventListener;

use App\Ordering\Domain\Entity\Order;
use App\Ordering\Domain\Enum\OrderStatus;
use App\Ordering\Domain\Event\OrderCancellationFinalizedEvent;
use App\Ordering\Domain\ValueObject\BuyerId;
use App\Ordering\Domain\ValueObject\OrderId;
use App\Ordering\Infrastructure\Messaging\EventListener\PublishOrderCancelledNotificationEventListener;
use App\Ordering\Infrastructure\Messaging\Outbox\OrderNotification\OrderNotificationMessage;
use App\Shared\Infrastructure\Messaging\Outbox\Writer\OutboxWriterInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(PublishOrderCancelledNotificationEventListener::class)]
final class PublishOrderCancelledNotificationEventListenerTest extends TestCase
{
    public function testListenerPublishesOrderCancelledNotificationToOutbox(): void
    {
        $order = new Order(
            new OrderId('00000000-0000-4000-8000-000000000003'),
            new BuyerId('buyer-3'),
            OrderStatus::CANCELLED,
        );

        $eventAt = new \DateTimeImmutable('2026-10-26 12:00:00+00:00');
        $event = new OrderCancellationFinalizedEvent($order, $eventAt);

        $outboxWriter = $this->createMock(OutboxWriterInterface::class);
        $outboxWriter->expects(self::once())
            ->method('append')
            ->with(self::callback(static function (object $msg): bool {
                if (!$msg instanceof OrderNotificationMessage) {
                    return false;
                }

                return '00000000-0000-4000-8000-000000000003' === $msg->orderId
                    && 'order_cancelled' === $msg->type
                    && 'buyer-3' === $msg->buyerId;
            }));

        $listener = new PublishOrderCancelledNotificationEventListener($outboxWriter);
        $listener($event);
    }
}
