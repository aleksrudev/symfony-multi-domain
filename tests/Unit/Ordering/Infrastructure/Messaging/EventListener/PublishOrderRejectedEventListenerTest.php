<?php

declare(strict_types=1);

namespace Tests\Unit\Ordering\Infrastructure\Messaging\EventListener;

use App\Ordering\Domain\Entity\Order;
use App\Ordering\Domain\Enum\OrderStatus;
use App\Ordering\Domain\Event\OrderRejectedEvent as DomainOrderRejectedEvent;
use App\Ordering\Domain\ValueObject\BuyerId;
use App\Ordering\Domain\ValueObject\OrderId;
use App\Ordering\Infrastructure\Messaging\EventListener\PublishOrderRejectedEventListener;
use App\Ordering\Presentation\Contracts\Event\OrderRejectedEvent as PublicOrderRejectedEvent;
use App\Shared\Infrastructure\Messaging\IntegrationEvent\IntegrationEventDispatcherInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(PublishOrderRejectedEventListener::class)]
final class PublishOrderRejectedEventListenerTest extends TestCase
{
    public function testPublishesPublicOrderRejectedEvent(): void
    {
        $order = new Order(
            new OrderId('00000000-0000-4000-8000-000000000002'),
            new BuyerId('buyer-2'),
            OrderStatus::REJECTED,
        );

        $eventAt = new \DateTimeImmutable('2026-10-27 12:00:00.123456+00:00');
        $event = new DomainOrderRejectedEvent($order, 'insufficient_stock', $eventAt);

        $dispatcher = $this->createMock(IntegrationEventDispatcherInterface::class);
        $dispatcher->expects(self::once())
            ->method('dispatch')
            ->with(self::callback(static function (object $publicEvent): bool {
                if (!$publicEvent instanceof PublicOrderRejectedEvent) {
                    return false;
                }

                return '00000000-0000-4000-8000-000000000002' === $publicEvent->orderId
                    && 'buyer-2' === $publicEvent->buyerId
                    && 'insufficient_stock' === $publicEvent->reason
                    && 'Ordering' === $publicEvent->source
                    && 'ordering.order_rejected' === $publicEvent->type
                    && 1 === $publicEvent->schemaVersion
                    && '2026-10-27T12:00:00.123456Z' === $publicEvent->eventAt;
            }));

        $listener = new PublishOrderRejectedEventListener($dispatcher);
        $listener($event);
    }
}
