<?php

declare(strict_types=1);

namespace Tests\Unit\Ordering\Infrastructure\Messaging\EventListener;

use App\Ordering\Domain\Entity\Order;
use App\Ordering\Domain\Enum\OrderStatus;
use App\Ordering\Domain\Event\OrderRejectedEvent;
use App\Ordering\Domain\ValueObject\BuyerId;
use App\Ordering\Domain\ValueObject\OrderId;
use App\Ordering\Infrastructure\Messaging\EventListener\PublishOrderRejectedNotificationEventListener;
use App\Ordering\Infrastructure\Messaging\Outbox\OrderNotification\OrderNotificationMessage;
use App\Shared\Infrastructure\Messaging\Outbox\Writer\OutboxWriterInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(PublishOrderRejectedNotificationEventListener::class)]
final class PublishOrderRejectedNotificationEventListenerTest extends TestCase
{
    public function testListenerPublishesOrderRejectedNotificationToOutbox(): void
    {
        $order = new Order(
            new OrderId('00000000-0000-4000-8000-000000000002'),
            new BuyerId('buyer-2'),
            OrderStatus::REJECTED,
        );

        $eventAt = new \DateTimeImmutable('2026-10-26 12:00:00+00:00');
        $event = new OrderRejectedEvent($order, 'insufficient_stock', $eventAt);

        $outboxWriter = $this->createMock(OutboxWriterInterface::class);
        $outboxWriter->expects(self::once())
            ->method('append')
            ->with(self::callback(static function (object $msg): bool {
                if (!$msg instanceof OrderNotificationMessage) {
                    return false;
                }

                return '00000000-0000-4000-8000-000000000002' === $msg->orderId
                    && 'order_rejected' === $msg->type
                    && 'buyer-2' === $msg->buyerId
                    && 'insufficient_stock' === ($msg->payload['reason'] ?? null);
            }));

        $listener = new PublishOrderRejectedNotificationEventListener($outboxWriter);
        $listener($event);
    }
}

