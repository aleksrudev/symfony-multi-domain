<?php

declare(strict_types=1);

namespace Tests\Unit\Inventory\Domain\Action;

use App\Inventory\Domain\Action\ReserveStockAction;
use App\Inventory\Domain\Collection\ReservationItemCollection;
use App\Inventory\Domain\DataProvider\ReservationDataProviderInterface;
use App\Inventory\Domain\DataProvider\StockPositionDataProviderInterface;
use App\Inventory\Domain\Entity\Reservation;
use App\Inventory\Domain\Entity\ReservationItem;
use App\Inventory\Domain\Entity\StockPosition;
use App\Inventory\Domain\Enum\ReservationRejectionReason;
use App\Inventory\Domain\Enum\ReservationStatus;
use App\Inventory\Domain\Exception\ReservationConflictException;
use App\Inventory\Domain\Exception\ReservationNotFoundException;
use App\Inventory\Domain\Exception\StockPositionNotFoundException;
use App\Inventory\Domain\Repository\ReservationRepositoryInterface;
use App\Inventory\Domain\Repository\StockPositionRepositoryInterface;
use App\Inventory\Domain\ValueObject\OrderId;
use App\Inventory\Domain\ValueObject\ProductId;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ReserveStockAction::class)]
final class ReserveStockActionTest extends TestCase
{
    private StockPositionDataProviderInterface $stockPositionDataProvider;
    private StockPositionRepositoryInterface $stockPositionRepository;
    private ReservationDataProviderInterface $reservationDataProvider;
    private ReservationRepositoryInterface $reservationRepository;
    private ReserveStockAction $action;

    protected function setUp(): void
    {
        $this->stockPositionDataProvider = $this->createMock(StockPositionDataProviderInterface::class);
        $this->stockPositionRepository = $this->createMock(StockPositionRepositoryInterface::class);
        $this->reservationDataProvider = $this->createMock(ReservationDataProviderInterface::class);
        $this->reservationRepository = $this->createMock(ReservationRepositoryInterface::class);

        $this->action = new ReserveStockAction(
            $this->stockPositionDataProvider,
            $this->stockPositionRepository,
            $this->reservationDataProvider,
            $this->reservationRepository,
        );
    }

    public function testReserveAllItemsSuccessfully(): void
    {
        $orderId = new OrderId('00000000-0000-4000-8000-000000000001');
        $posA = new StockPosition(new ProductId('prod-a'), onHand: 10, reserved: 2);
        $posB = new StockPosition(new ProductId('prod-b'), onHand: 5, reserved: 1);

        $this->reservationDataProvider
            ->method('findByOrderId')
            ->with($orderId, true)
            ->willThrowException(new ReservationNotFoundException($orderId->value));

        $this->stockPositionDataProvider
            ->method('findByProductId')
            ->willReturnMap([
                [$posA->productId(), true, $posA],
                [$posB->productId(), true, $posB],
            ]);

        $this->stockPositionRepository
            ->expects(self::exactly(2))
            ->method('save');

        $this->reservationRepository
            ->expects(self::once())
            ->method('save')
            ->with(self::callback(static function (Reservation $res) use ($orderId): bool {
                return $res->orderId()->equals($orderId) && ReservationStatus::RESERVED === $res->status();
            }));

        $items = new ReservationItemCollection([
            new ReservationItem(new ProductId('prod-b'), 2),
            new ReservationItem(new ProductId('prod-a'), 3),
        ]);

        $result = ($this->action)($orderId, $items);

        self::assertSame(ReservationStatus::RESERVED, $result->status());
        self::assertSame(5, $posA->reserved());
        self::assertSame(3, $posB->reserved());
    }

    public function testAtomicFailureWhenOneItemInsufficient(): void
    {
        $orderId = new OrderId('00000000-0000-4000-8000-000000000001');
        $posA = new StockPosition(new ProductId('prod-a'), onHand: 10, reserved: 2); // available 8
        $posB = new StockPosition(new ProductId('prod-b'), onHand: 5, reserved: 4); // available 1

        $this->reservationDataProvider
            ->method('findByOrderId')
            ->willThrowException(new ReservationNotFoundException($orderId->value));

        $this->stockPositionDataProvider
            ->method('findByProductId')
            ->willReturnMap([
                [$posA->productId(), true, $posA],
                [$posB->productId(), true, $posB],
            ]);

        // No positions should be saved
        $this->stockPositionRepository
            ->expects(self::never())
            ->method('save');

        $this->reservationRepository
            ->expects(self::once())
            ->method('save')
            ->with(self::callback(static function (Reservation $res) use ($orderId): bool {
                return $res->orderId()->equals($orderId)
                    && ReservationStatus::REJECTED === $res->status()
                    && ReservationRejectionReason::INSUFFICIENT_STOCK === $res->rejectionReason();
            }));

        $items = new ReservationItemCollection([
            new ReservationItem(new ProductId('prod-a'), 3), // can fulfill
            new ReservationItem(new ProductId('prod-b'), 2), // cannot fulfill (only 1 available)
        ]);

        $result = ($this->action)($orderId, $items);

        self::assertSame(ReservationStatus::REJECTED, $result->status());
        self::assertSame(2, $posA->reserved()); // Unmodified!
        self::assertSame(4, $posB->reserved()); // Unmodified!
    }

    public function testRejectionWhenProductNotFound(): void
    {
        $orderId = new OrderId('00000000-0000-4000-8000-000000000001');

        $this->reservationDataProvider
            ->method('findByOrderId')
            ->willThrowException(new ReservationNotFoundException($orderId->value));

        $this->stockPositionDataProvider
            ->method('findByProductId')
            ->willThrowException(new StockPositionNotFoundException('prod-unknown'));

        $this->stockPositionRepository
            ->expects(self::never())
            ->method('save');

        $this->reservationRepository
            ->expects(self::once())
            ->method('save')
            ->with(self::callback(static function (Reservation $res): bool {
                return ReservationStatus::REJECTED === $res->status()
                    && ReservationRejectionReason::PRODUCT_NOT_FOUND === $res->rejectionReason();
            }));

        $items = new ReservationItemCollection([
            new ReservationItem(new ProductId('prod-unknown'), 1),
        ]);

        $result = ($this->action)($orderId, $items);

        self::assertSame(ReservationStatus::REJECTED, $result->status());
        self::assertSame(ReservationRejectionReason::PRODUCT_NOT_FOUND, $result->rejectionReason());
    }

    public function testIdempotentRepeatWithSameItems(): void
    {
        $orderId = new OrderId('00000000-0000-4000-8000-000000000001');
        $existing = Reservation::createReserved($orderId, new ReservationItemCollection([
            new ReservationItem(new ProductId('prod-a'), 2),
        ]));

        $this->reservationDataProvider
            ->method('findByOrderId')
            ->with($orderId, true)
            ->willReturn($existing);

        // Neither stock position nor reservation save should be called
        $this->stockPositionRepository->expects(self::never())->method('save');
        $this->reservationRepository->expects(self::never())->method('save');

        $items = new ReservationItemCollection([
            new ReservationItem(new ProductId('prod-a'), 2),
        ]);

        $result = ($this->action)($orderId, $items);

        self::assertSame($existing, $result);
    }

    public function testConflictingRepeatThrowsException(): void
    {
        $orderId = new OrderId('00000000-0000-4000-8000-000000000001');
        $existing = Reservation::createReserved($orderId, new ReservationItemCollection([
            new ReservationItem(new ProductId('prod-a'), 2),
        ]));

        $this->reservationDataProvider
            ->method('findByOrderId')
            ->willReturn($existing);

        $items = new ReservationItemCollection([
            new ReservationItem(new ProductId('prod-a'), 5), // Different quantity!
        ]);

        $this->expectException(ReservationConflictException::class);

        ($this->action)($orderId, $items);
    }

    public function testDefensiveReleasePreventsLateReservation(): void
    {
        $orderId = new OrderId('00000000-0000-4000-8000-000000000001');
        $defensiveRecord = Reservation::createDefensiveReleased($orderId);

        $this->reservationDataProvider
            ->method('findByOrderId')
            ->willReturn($defensiveRecord);

        // No positions locked or saved
        $this->stockPositionDataProvider->expects(self::never())->method('findByProductId');
        $this->stockPositionRepository->expects(self::never())->method('save');

        $items = new ReservationItemCollection([
            new ReservationItem(new ProductId('prod-a'), 2),
        ]);

        $result = ($this->action)($orderId, $items);

        self::assertSame(ReservationStatus::RELEASED, $result->status());
        self::assertSame($defensiveRecord, $result);
    }
}

