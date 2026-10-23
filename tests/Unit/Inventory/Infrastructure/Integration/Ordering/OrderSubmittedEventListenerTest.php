<?php

declare(strict_types=1);

namespace Tests\Unit\Inventory\Infrastructure\Integration\Ordering;

use App\Inventory\Infrastructure\Integration\Ordering\OrderSubmittedEventListener;
use App\Inventory\Infrastructure\Messaging\Inbox\ReserveStock\ReserveStockInboxMessage;
use App\Ordering\Presentation\Contracts\Dto\SubmittedOrderItem;
use App\Ordering\Presentation\Contracts\Event\OrderSubmittedEvent;
use App\Shared\Application\Bus\Inbox\InboxBusInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(OrderSubmittedEventListener::class)]
final class OrderSubmittedEventListenerTest extends TestCase
{
    public function testListenerDispatchesReserveStockInboxMessage(): void
    {
        $inboxBus = $this->createMock(InboxBusInterface::class);
        $listener = new OrderSubmittedEventListener($inboxBus);

        $orderId = '00000000-0000-4000-8000-000000000001';
        $event = new OrderSubmittedEvent(
            eventId: '00000000-0000-7000-8000-000000000001',
            eventAt: '2026-10-23T10:00:00.000000Z',
            type: 'ordering.order_submitted',
            source: 'ordering',
            schemaVersion: 1,
            orderId: $orderId,
            buyerId: 'buyer-1',
            items: [
                new SubmittedOrderItem('prod-keyboard', 2, 'Keyboard', 150000),
                new SubmittedOrderItem('prod-mouse', 1, 'Mouse', 50000),
            ],
            totalAmount: 350000,
            currency: 'RUB',
        );

        $inboxBus
            ->expects(self::once())
            ->method('dispatch')
            ->with(self::callback(static function (ReserveStockInboxMessage $msg) use ($orderId): bool {
                return $msg->orderId === $orderId
                    && 2 === count($msg->items)
                    && 'prod-keyboard' === $msg->items[0]->productId
                    && 2 === $msg->items[0]->quantity
                    && 'prod-mouse' === $msg->items[1]->productId
                    && 1 === $msg->items[1]->quantity;
            }));

        $listener($event);
    }
}
