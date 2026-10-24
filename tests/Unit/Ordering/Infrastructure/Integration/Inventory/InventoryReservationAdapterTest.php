<?php

declare(strict_types=1);

namespace Tests\Unit\Ordering\Infrastructure\Integration\Inventory;

use App\Inventory\Presentation\Contracts\Dto\ReservationRequest;
use App\Inventory\Presentation\Contracts\InventoryContractInterface;
use App\Ordering\Infrastructure\Integration\Inventory\InventoryReservationAdapter;
use App\Ordering\Infrastructure\Messaging\Outbox\RequestReservation\RequestReservationOutboxItem;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(InventoryReservationAdapter::class)]
final class InventoryReservationAdapterTest extends TestCase
{
    public function testAdapterCallsInventoryContract(): void
    {
        $contract = $this->createMock(InventoryContractInterface::class);
        $adapter = new InventoryReservationAdapter($contract);

        $orderId = '00000000-0000-4000-8000-000000000001';
        $items = [
            new RequestReservationOutboxItem('prod-1', 2),
            new RequestReservationOutboxItem('prod-2', 4),
        ];

        $contract
            ->expects(self::once())
            ->method('requestReservation')
            ->with(self::callback(static function (ReservationRequest $request) use ($orderId): bool {
                return $request->orderId === $orderId
                    && 2 === count($request->items)
                    && 'prod-1' === $request->items[0]->productId
                    && 2 === $request->items[0]->quantity
                    && 'prod-2' === $request->items[1]->productId
                    && 4 === $request->items[1]->quantity;
            }));

        $adapter->requestReservation($orderId, $items);
    }
}
