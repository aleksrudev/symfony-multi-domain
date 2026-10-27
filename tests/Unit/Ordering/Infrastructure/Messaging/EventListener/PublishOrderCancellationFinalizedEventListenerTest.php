<?php

declare(strict_types=1);

namespace Tests\Unit\Ordering\Infrastructure\Messaging\EventListener;

use App\Ordering\Domain\Entity\Order;
use App\Ordering\Domain\Enum\OrderStatus;
use App\Ordering\Domain\Event\OrderCancellationFinalizedEvent as DomainOrderCancellationFinalizedEvent;
use App\Ordering\Domain\ValueObject\BuyerId;
use App\Ordering\Domain\ValueObject\OrderId;
use App\Ordering\Infrastructure\Messaging\EventListener\PublishOrderCancellationFinalizedEventListener;
use App\Ordering\Presentation\Contracts\Event\OrderCancellationFinalizedEvent as PublicOrderCancellationFinalizedEvent;
use App\Shared\Infrastructure\Messaging\IntegrationEvent\IntegrationEventDispatcherInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(PublishOrderCancellationFinalizedEventListener::class)]
final class PublishOrderCancellationFinalizedEventListenerTest extends TestCase
{
    public function testPublishesPublicOrderCancellationFinalizedEvent(): void
    {
        $order = new Order(
            new OrderId('00000000-0000-4000-8000-000000000003'),
            new BuyerId('buyer-3'),
            OrderStatus::CANCELLED,
        );

        $eventAt = new \DateTimeImmutable('2026-10-27 12:00:00.123456+00:00');
        $event = new DomainOrderCancellationFinalizedEvent($order, $eventAt);

        $dispatcher = $this->createMock(IntegrationEventDispatcherInterface::class);
        $dispatcher->expects(self::once())
            ->method('dispatch')
            ->with(self::callback(static function (object $publicEvent): bool {
                if (!$publicEvent instanceof PublicOrderCancellationFinalizedEvent) {
                    return false;
                }

                return '00000000-0000-4000-8000-000000000003' === $publicEvent->orderId
                    && 'buyer-3' === $publicEvent->buyerId
                    && 'Ordering' === $publicEvent->source
                    && 'ordering.order_cancellation_finalized' === $publicEvent->type
                    && 1 === $publicEvent->schemaVersion
                    && '2026-10-27T12:00:00.123456Z' === $publicEvent->eventAt;
            }));

        $listener = new PublishOrderCancellationFinalizedEventListener($dispatcher);
        $listener($event);
    }
}
