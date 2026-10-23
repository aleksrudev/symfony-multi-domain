<?php

declare(strict_types=1);

namespace Tests\Unit\Ordering\Infrastructure\Integration\Inventory;

use App\Inventory\Presentation\Contracts\Dto\ReservationRequest;
use App\Inventory\Presentation\Contracts\InventoryContractInterface;
use App\Ordering\Infrastructure\Integration\Inventory\OrderSubmittedInventoryListener;
use App\Ordering\Presentation\Contracts\Dto\SubmittedOrderItem;
use App\Ordering\Presentation\Contracts\Event\OrderSubmittedEvent;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(OrderSubmittedInventoryListener::class)]
final class OrderSubmittedInventoryListenerTest extends TestCase
{
    public function testListenerInvokesInventoryContract(): void
    {
        $contract = $this->createMock(InventoryContractInterface::class);
        $listener = new OrderSubmittedInventoryListener($contract);

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

        $contract
            ->expects(self::once())
            ->method('requestReservation')
            ->with(self::callback(static function (ReservationRequest $req) use ($orderId): bool {
                return $req->orderId === $orderId
                    && 2 === count($req->items)
                    && 'prod-keyboard' === $req->items[0]->productId
                    && 2 === $req->items[0]->quantity
                    && 'prod-mouse' === $req->items[1]->productId
                    && 1 === $req->items[1]->quantity;
            }));

        $listener($event);
    }
}

