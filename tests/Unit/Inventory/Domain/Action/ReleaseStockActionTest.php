<?php

declare(strict_types=1);

namespace Tests\Unit\Inventory\Domain\Action;

use App\Inventory\Domain\Action\ReleaseStockAction;
use App\Inventory\Domain\Collection\ReservationItemCollection;
use App\Inventory\Domain\DataProvider\ReservationDataProviderInterface;
use App\Inventory\Domain\DataProvider\StockPositionDataProviderInterface;
use App\Inventory\Domain\Entity\Reservation;
use App\Inventory\Domain\Entity\ReservationItem;
use App\Inventory\Domain\Entity\StockPosition;
use App\Inventory\Domain\Enum\ReservationStatus;
use App\Inventory\Domain\Exception\ReservationNotFoundException;
use App\Inventory\Domain\Repository\ReservationRepositoryInterface;
use App\Inventory\Domain\Repository\StockPositionRepositoryInterface;
use App\Inventory\Domain\ValueObject\OrderId;
use App\Inventory\Domain\ValueObject\ProductId;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

#[CoversClass(ReleaseStockAction::class)]
final class ReleaseStockActionTest extends TestCase
{
    private StockPositionDataProviderInterface&MockObject $stockPositionDataProvider;
    private StockPositionRepositoryInterface&MockObject $stockPositionRepository;
    private ReservationDataProviderInterface&MockObject $reservationDataProvider;
    private ReservationRepositoryInterface&MockObject $reservationRepository;
    private ReleaseStockAction $action;

    protected function setUp(): void
    {
        $this->stockPositionDataProvider = $this->createMock(StockPositionDataProviderInterface::class);
        $this->stockPositionRepository = $this->createMock(StockPositionRepositoryInterface::class);
        $this->reservationDataProvider = $this->createMock(ReservationDataProviderInterface::class);
        $this->reservationRepository = $this->createMock(ReservationRepositoryInterface::class);

        $this->action = new ReleaseStockAction(
            $this->stockPositionDataProvider,
            $this->stockPositionRepository,
            $this->reservationDataProvider,
            $this->reservationRepository,
        );
    }

    public function testReleaseExistingReservedReservation(): void
    {
        $orderId = new OrderId('00000000-0000-4000-8000-000000000001');
        $pos = new StockPosition(new ProductId('prod-1'), onHand: 10, reserved: 4);

        $reservation = Reservation::createReserved($orderId, new ReservationItemCollection([
            new ReservationItem(new ProductId('prod-1'), 3),
        ]));

        $this->reservationDataProvider
            ->method('findByOrderId')
            ->with($orderId, true)
            ->willReturn($reservation);

        $this->stockPositionDataProvider
            ->method('findByProductId')
            ->with($pos->productId(), true)
            ->willReturn($pos);

        $this->stockPositionRepository
            ->expects(self::once())
            ->method('save')
            ->with($pos);

        $this->reservationRepository
            ->expects(self::once())
            ->method('save')
            ->with($reservation);

        $result = ($this->action)($orderId);

        self::assertSame(ReservationStatus::RELEASED, $result->status());
        self::assertSame(1, $pos->reserved());
    }

    public function testReleaseCreatesDefensiveRecordWhenNotFound(): void
    {
        $orderId = new OrderId('00000000-0000-4000-8000-000000000001');

        $this->reservationDataProvider
            ->method('findByOrderId')
            ->with($orderId, true)
            ->willThrowException(new ReservationNotFoundException($orderId->value));

        $this->stockPositionDataProvider->expects(self::never())->method('findByProductId');
        $this->stockPositionRepository->expects(self::never())->method('save');

        $this->reservationRepository
            ->expects(self::once())
            ->method('save')
            ->with(self::callback(static function (Reservation $res) use ($orderId): bool {
                return $res->orderId()->equals($orderId) && ReservationStatus::RELEASED === $res->status();
            }));

        $result = ($this->action)($orderId);

        self::assertSame(ReservationStatus::RELEASED, $result->status());
    }

    public function testReleaseAlreadyReleasedIsIdempotent(): void
    {
        $orderId = new OrderId('00000000-0000-4000-8000-000000000001');
        $reservation = Reservation::createDefensiveReleased($orderId);

        $this->reservationDataProvider
            ->method('findByOrderId')
            ->willReturn($reservation);

        $this->stockPositionRepository->expects(self::never())->method('save');
        $this->reservationRepository->expects(self::never())->method('save');

        $result = ($this->action)($orderId);

        self::assertSame(ReservationStatus::RELEASED, $result->status());
    }
}
