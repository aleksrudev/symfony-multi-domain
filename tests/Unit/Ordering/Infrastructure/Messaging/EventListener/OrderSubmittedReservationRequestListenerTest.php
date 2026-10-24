<?php

declare(strict_types=1);

namespace Tests\Unit\Ordering\Infrastructure\Messaging\EventListener;

use App\Ordering\Domain\Collection\OrderItemCollection;
use App\Ordering\Domain\Entity\Order;
use App\Ordering\Domain\Entity\OrderItem;
use App\Ordering\Domain\Event\OrderSubmittedEvent;
use App\Ordering\Domain\ValueObject\BuyerId;
use App\Ordering\Domain\ValueObject\OrderId;
use App\Ordering\Domain\ValueObject\ProductId;
use App\Ordering\Infrastructure\Messaging\EventListener\OrderSubmittedReservationRequestListener;
use App\Ordering\Infrastructure\Messaging\Outbox\RequestReservation\RequestReservationOutboxMessage;
use App\Shared\Application\Bus\Outbox\OutboxBusInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(OrderSubmittedReservationRequestListener::class)]
final class OrderSubmittedReservationRequestListenerTest extends TestCase
{
    public function testListenerPublishesRequestReservationOutboxMessage(): void
    {
        $outboxBus = $this->createMock(OutboxBusInterface::class);
        $listener = new OrderSubmittedReservationRequestListener($outboxBus);

        $orderId = new OrderId('00000000-0000-4000-8000-000000000001');
        $buyerId = new BuyerId('buyer-1');
        $items = new OrderItemCollection([
            new OrderItem(new ProductId('prod-1'), 2),
            new OrderItem(new ProductId('prod-2'), 5),
        ]);

        $order = Order::createDraft($orderId, $buyerId);
        $order->replaceItems($items);
        $event = new OrderSubmittedEvent($order, new \DateTimeImmutable('2026-10-24T10:00:00Z'));

        $outboxBus
            ->expects(self::once())
            ->method('publish')
            ->with(self::callback(static function (RequestReservationOutboxMessage $msg) use ($orderId): bool {
                return $msg->orderId === $orderId->value
                    && 2 === count($msg->items)
                    && 'prod-1' === $msg->items[0]->productId
                    && 2 === $msg->items[0]->quantity
                    && 'prod-2' === $msg->items[1]->productId
                    && 5 === $msg->items[1]->quantity;
            }));

        $listener($event);
    }
}
