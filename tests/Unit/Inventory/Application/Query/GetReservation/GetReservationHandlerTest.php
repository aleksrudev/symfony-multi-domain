<?php

declare(strict_types=1);

namespace Tests\Unit\Inventory\Application\Query\GetReservation;

use App\Inventory\Application\Query\GetReservation\GetReservationHandler;
use App\Inventory\Application\Query\GetReservation\GetReservationQuery;
use App\Inventory\Domain\Collection\ReservationItemCollection;
use App\Inventory\Domain\DataProvider\ReservationDataProviderInterface;
use App\Inventory\Domain\Entity\Reservation;
use App\Inventory\Domain\Entity\ReservationItem;
use App\Inventory\Domain\Enum\ReservationStatus;
use App\Inventory\Domain\ValueObject\OrderId;
use App\Inventory\Domain\ValueObject\ProductId;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(GetReservationHandler::class)]
final class GetReservationHandlerTest extends TestCase
{
    public function testHandlerReturnsQueryResult(): void
    {
        $orderIdStr = '00000000-0000-4000-8000-000000000001';
        $orderId = new OrderId($orderIdStr);

        $reservation = Reservation::createReserved($orderId, new ReservationItemCollection([
            new ReservationItem(new ProductId('prod-1'), 3),
        ]));

        $dataProvider = $this->createMock(ReservationDataProviderInterface::class);
        $dataProvider
            ->expects(self::once())
            ->method('findByOrderId')
            ->with(self::callback(static fn (OrderId $id): bool => $id->value === $orderIdStr))
            ->willReturn($reservation);

        $handler = new GetReservationHandler($dataProvider);
        $result = $handler(new GetReservationQuery($orderIdStr));

        self::assertSame($orderIdStr, $result->orderId);
        self::assertSame(ReservationStatus::RESERVED->value, $result->status);
        self::assertNull($result->rejectionReason);
        self::assertCount(1, $result->items);
        self::assertSame('prod-1', $result->items[0]['productId']);
        self::assertSame(3, $result->items[0]['quantity']);
    }
}

