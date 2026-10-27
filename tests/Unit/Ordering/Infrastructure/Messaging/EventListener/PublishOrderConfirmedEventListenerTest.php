<?php

declare(strict_types=1);

namespace Tests\Unit\Ordering\Infrastructure\Messaging\EventListener;

use App\Ordering\Domain\Collection\OrderItemCollection;
use App\Ordering\Domain\Collection\ProductSnapshotCollection;
use App\Ordering\Domain\Entity\Order;
use App\Ordering\Domain\Entity\OrderItem;
use App\Ordering\Domain\Enum\Currency;
use App\Ordering\Domain\Event\OrderConfirmedEvent as DomainOrderConfirmedEvent;
use App\Ordering\Domain\ValueObject\BuyerId;
use App\Ordering\Domain\ValueObject\OrderId;
use App\Ordering\Domain\ValueObject\ProductId;
use App\Ordering\Domain\ValueObject\ProductSnapshot;
use App\Ordering\Infrastructure\Messaging\EventListener\PublishOrderConfirmedEventListener;
use App\Ordering\Presentation\Contracts\Event\OrderConfirmedEvent as PublicOrderConfirmedEvent;
use App\Shared\Infrastructure\Messaging\IntegrationEvent\IntegrationEventDispatcherInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(PublishOrderConfirmedEventListener::class)]
final class PublishOrderConfirmedEventListenerTest extends TestCase
{
    public function testPublishesPublicOrderConfirmedEvent(): void
    {
        $order = Order::createDraft(
            new OrderId('00000000-0000-4000-8000-000000000001'),
            new BuyerId('buyer-1'),
        );
        $order->replaceItems(new OrderItemCollection([
            new OrderItem(new ProductId('prod-1'), 2),
        ]));
        $order->submit(new ProductSnapshotCollection([
            new ProductSnapshot(new ProductId('prod-1'), 'Product 1', 10000, Currency::RUB),
        ]));
        $order->confirm();

        $eventAt = new \DateTimeImmutable('2026-10-27 12:00:00.123456+00:00');
        $event = new DomainOrderConfirmedEvent($order, $eventAt);

        $dispatcher = $this->createMock(IntegrationEventDispatcherInterface::class);
        $dispatcher->expects(self::once())
            ->method('dispatch')
            ->with(self::callback(static function (object $publicEvent): bool {
                if (!$publicEvent instanceof PublicOrderConfirmedEvent) {
                    return false;
                }

                return '00000000-0000-4000-8000-000000000001' === $publicEvent->orderId
                    && 'buyer-1' === $publicEvent->buyerId
                    && 20000 === $publicEvent->totalAmount
                    && 'RUB' === $publicEvent->currency
                    && 'Ordering' === $publicEvent->source
                    && 'ordering.order_confirmed' === $publicEvent->type
                    && 1 === $publicEvent->schemaVersion
                    && '2026-10-27T12:00:00.123456Z' === $publicEvent->eventAt;
            }));

        $listener = new PublishOrderConfirmedEventListener($dispatcher);
        $listener($event);
    }
}
