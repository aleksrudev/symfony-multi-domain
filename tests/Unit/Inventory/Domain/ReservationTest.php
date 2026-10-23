<?php

declare(strict_types=1);

namespace Tests\Unit\Inventory\Domain;

use App\Inventory\Domain\Collection\ReservationItemCollection;
use App\Inventory\Domain\Entity\Reservation;
use App\Inventory\Domain\Entity\ReservationItem;
use App\Inventory\Domain\Enum\ReservationRejectionReason;
use App\Inventory\Domain\Enum\ReservationStatus;
use App\Inventory\Domain\ValueObject\OrderId;
use App\Inventory\Domain\ValueObject\ProductId;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Reservation::class)]
#[CoversClass(ReservationItem::class)]
final class ReservationTest extends TestCase
{
    public function testCreateReserved(): void
    {
        $orderId = new OrderId('00000000-0000-4000-8000-000000000001');
        $items = new ReservationItemCollection([
            new ReservationItem(new ProductId('prod-1'), 2),
            new ReservationItem(new ProductId('prod-2'), 1),
        ]);

        $reservation = Reservation::createReserved($orderId, $items);

        self::assertSame($orderId->value, $reservation->orderId()->value);
        self::assertSame(ReservationStatus::RESERVED, $reservation->status());
        self::assertNull($reservation->rejectionReason());
        self::assertCount(2, $reservation->items());
    }

    public function testCreateRejected(): void
    {
        $orderId = new OrderId('00000000-0000-4000-8000-000000000001');
        $items = new ReservationItemCollection([
            new ReservationItem(new ProductId('prod-1'), 5),
        ]);

        $reservation = Reservation::createRejected(
            $orderId,
            $items,
            ReservationRejectionReason::INSUFFICIENT_STOCK,
        );

        self::assertSame(ReservationStatus::REJECTED, $reservation->status());
        self::assertSame(ReservationRejectionReason::INSUFFICIENT_STOCK, $reservation->rejectionReason());
    }

    public function testCreateDefensiveReleased(): void
    {
        $orderId = new OrderId('00000000-0000-4000-8000-000000000001');
        $reservation = Reservation::createDefensiveReleased($orderId);

        self::assertSame(ReservationStatus::RELEASED, $reservation->status());
        self::assertCount(0, $reservation->items());
    }

    public function testReleaseTransition(): void
    {
        $orderId = new OrderId('00000000-0000-4000-8000-000000000001');
        $reservation = Reservation::createReserved($orderId, new ReservationItemCollection());

        $reservation->release();

        self::assertSame(ReservationStatus::RELEASED, $reservation->status());
    }

    public function testHasSameItemsIdenticalAndDifferentOrder(): void
    {
        $orderId = new OrderId('00000000-0000-4000-8000-000000000001');
        $items1 = new ReservationItemCollection([
            new ReservationItem(new ProductId('prod-b'), 2),
            new ReservationItem(new ProductId('prod-a'), 1),
        ]);

        $reservation = Reservation::createReserved($orderId, $items1);

        $itemsSame = new ReservationItemCollection([
            new ReservationItem(new ProductId('prod-a'), 1),
            new ReservationItem(new ProductId('prod-b'), 2),
        ]);

        $itemsDifferentQty = new ReservationItemCollection([
            new ReservationItem(new ProductId('prod-a'), 2),
            new ReservationItem(new ProductId('prod-b'), 2),
        ]);

        $itemsDifferentProduct = new ReservationItemCollection([
            new ReservationItem(new ProductId('prod-a'), 1),
            new ReservationItem(new ProductId('prod-c'), 2),
        ]);

        self::assertTrue($reservation->hasSameItems($itemsSame));
        self::assertFalse($reservation->hasSameItems($itemsDifferentQty));
        self::assertFalse($reservation->hasSameItems($itemsDifferentProduct));
    }

    public function testReservationItemThrowsOnNegativeOrZeroQuantity(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new ReservationItem(new ProductId('prod-1'), 0);
    }
}

