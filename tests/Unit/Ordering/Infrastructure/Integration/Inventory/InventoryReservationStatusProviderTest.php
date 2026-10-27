<?php

declare(strict_types=1);

namespace Tests\Unit\Ordering\Infrastructure\Integration\Inventory;

use App\Inventory\Presentation\Contracts\Dto\ReservationResult;
use App\Inventory\Presentation\Contracts\InventoryContractInterface;
use App\Ordering\Infrastructure\Integration\Inventory\InventoryReservationStatusProvider;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(InventoryReservationStatusProvider::class)]
final class InventoryReservationStatusProviderTest extends TestCase
{
    public function testGetReservationStatusAdaptsContractDto(): void
    {
        $orderId = '00000000-0000-4000-8000-000000000001';

        $contract = $this->createMock(InventoryContractInterface::class);
        $contract->expects(self::once())
            ->method('getReservationResult')
            ->with($orderId)
            ->willReturn(new ReservationResult(
                orderId: $orderId,
                status: 'reserved',
                rejectionReason: null,
            ));

        $provider = new InventoryReservationStatusProvider($contract);
        $status = $provider->getReservationStatus($orderId);

        self::assertSame($orderId, $status->orderId);
        self::assertSame('reserved', $status->status);
        self::assertNull($status->rejectionReason);
    }
}
