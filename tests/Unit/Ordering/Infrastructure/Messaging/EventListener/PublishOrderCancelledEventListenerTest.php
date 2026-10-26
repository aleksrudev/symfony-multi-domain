<?php

declare(strict_types=1);

namespace Tests\Unit\Ordering\Infrastructure\Messaging\EventListener;

use App\Ordering\Domain\Entity\Order;
use App\Ordering\Domain\Enum\OrderStatus;
use App\Ordering\Domain\Event\OrderCancellingEvent;
use App\Ordering\Domain\ValueObject\BuyerId;
use App\Ordering\Domain\ValueObject\OrderId;
use App\Ordering\Infrastructure\Messaging\EventListener\PublishOrderCancelledEventListener;
use App\Ordering\Presentation\Contracts\Event\OrderCancelledEvent;
use App\Shared\Infrastructure\Messaging\IntegrationEvent\IntegrationEventDispatcherInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(PublishOrderCancelledEventListener::class)]
final class PublishOrderCancelledEventListenerTest extends TestCase
{
    public function testListenerPublishesOrderCancelledIntegrationEvent(): void
    {
        $order = new Order(
            new OrderId('00000000-0000-4000-8000-000000000001'),
            new BuyerId('buyer-1'),
            OrderStatus::CANCELLING,
        );

        $eventAt = new \DateTimeImmutable('2026-10-26 12:00:00+00:00');
        $event = new OrderCancellingEvent($order, $eventAt);

        $dispatcher = $this->createMock(IntegrationEventDispatcherInterface::class);
        $dispatcher->expects(self::once())
            ->method('dispatch')
            ->with(self::callback(static function (object $publicEvent): bool {
                if (!$publicEvent instanceof OrderCancelledEvent) {
                    return false;
                }

                return '00000000-0000-4000-8000-000000000001' === $publicEvent->orderId
                    && 'buyer-1' === $publicEvent->buyerId
                    && 'Ordering' === $publicEvent->source
                    && 'ordering.order_cancelled' === $publicEvent->type
                    && 1 === $publicEvent->schemaVersion;
            }));

        $listener = new PublishOrderCancelledEventListener($dispatcher);
        $listener($event);
    }
}
