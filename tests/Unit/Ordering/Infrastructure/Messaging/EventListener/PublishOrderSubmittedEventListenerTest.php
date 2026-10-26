<?php

declare(strict_types=1);

namespace Tests\Unit\Ordering\Infrastructure\Messaging\EventListener;

use App\Ordering\Domain\Collection\OrderItemCollection;
use App\Ordering\Domain\Collection\ProductSnapshotCollection;
use App\Ordering\Domain\Entity\Order;
use App\Ordering\Domain\Entity\OrderItem;
use App\Ordering\Domain\Enum\Currency;
use App\Ordering\Domain\Event\OrderSubmittedEvent as DomainOrderSubmittedEvent;
use App\Ordering\Domain\ValueObject\BuyerId;
use App\Ordering\Domain\ValueObject\OrderId;
use App\Ordering\Domain\ValueObject\ProductId;
use App\Ordering\Domain\ValueObject\ProductSnapshot;
use App\Ordering\Infrastructure\Messaging\EventListener\PublishOrderSubmittedEventListener;
use App\Ordering\Presentation\Contracts\Event\OrderSubmittedEvent as PublicOrderSubmittedEvent;
use App\Shared\Infrastructure\Messaging\IntegrationEvent\IntegrationEventDispatcherInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(PublishOrderSubmittedEventListener::class)]
final class PublishOrderSubmittedEventListenerTest extends TestCase
{
    public function testListenerPublishesIntegrationEvent(): void
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

        $eventAt = new \DateTimeImmutable('2026-10-19 12:00:00.123456+00:00');
        $event = new DomainOrderSubmittedEvent($order, $eventAt);

        $dispatcher = $this->createMock(IntegrationEventDispatcherInterface::class);
        $dispatcher->expects(self::once())
            ->method('dispatch')
            ->with(self::callback(static function (object $publicEvent): bool {
                if (!$publicEvent instanceof PublicOrderSubmittedEvent) {
                    return false;
                }

                return '00000000-0000-4000-8000-000000000001' === $publicEvent->orderId
                    && 'buyer-1' === $publicEvent->buyerId
                    && 20000 === $publicEvent->totalAmount
                    && 'RUB' === $publicEvent->currency
                    && 'Ordering' === $publicEvent->source
                    && 'ordering.order_submitted' === $publicEvent->type
                    && 1 === $publicEvent->schemaVersion
                    && 1 === count($publicEvent->items)
                    && 'prod-1' === $publicEvent->items[0]->productId
                    && 2 === $publicEvent->items[0]->quantity
                    && 'Product 1' === $publicEvent->items[0]->productName
                    && 10000 === $publicEvent->items[0]->priceAmount;
            }));

        $listener = new PublishOrderSubmittedEventListener($dispatcher);
        $listener($event);
    }
}
