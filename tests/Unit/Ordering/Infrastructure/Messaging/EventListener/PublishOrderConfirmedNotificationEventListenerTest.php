<?php

declare(strict_types=1);

namespace Tests\Unit\Ordering\Infrastructure\Messaging\EventListener;

use App\Ordering\Domain\Entity\Order;
use App\Ordering\Domain\Enum\OrderStatus;
use App\Ordering\Domain\Event\OrderConfirmedEvent;
use App\Ordering\Domain\ValueObject\BuyerId;
use App\Ordering\Domain\ValueObject\OrderId;
use App\Ordering\Infrastructure\Messaging\EventListener\PublishOrderConfirmedNotificationEventListener;
use App\Ordering\Infrastructure\Messaging\Outbox\OrderNotification\OrderNotificationMessage;
use App\Shared\Infrastructure\Messaging\Outbox\Writer\OutboxWriterInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(PublishOrderConfirmedNotificationEventListener::class)]
final class PublishOrderConfirmedNotificationEventListenerTest extends TestCase
{
    public function testListenerPublishesOrderConfirmedNotificationToOutbox(): void
    {
        $order = new Order(
            new OrderId('00000000-0000-4000-8000-000000000001'),
            new BuyerId('buyer-1'),
            OrderStatus::CONFIRMED,
            totalAmount: 15000,
        );

        $eventAt = new \DateTimeImmutable('2026-10-26 12:00:00+00:00');
        $event = new OrderConfirmedEvent($order, $eventAt);

        $outboxWriter = $this->createMock(OutboxWriterInterface::class);
        $outboxWriter->expects(self::once())
            ->method('append')
            ->with(self::callback(static function (object $msg): bool {
                if (!$msg instanceof OrderNotificationMessage) {
                    return false;
                }

                return '00000000-0000-4000-8000-000000000001' === $msg->orderId
                    && 'order_confirmed' === $msg->type
                    && 'buyer-1' === $msg->buyerId
                    && 15000 === ($msg->payload['totalAmount'] ?? null);
            }));

        $listener = new PublishOrderConfirmedNotificationEventListener($outboxWriter);
        $listener($event);
    }
}

