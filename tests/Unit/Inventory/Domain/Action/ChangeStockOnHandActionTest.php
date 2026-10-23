<?php

declare(strict_types=1);

namespace Tests\Unit\Inventory\Domain\Action;

use App\Inventory\Domain\Action\ChangeStockOnHandAction;
use App\Inventory\Domain\DataProvider\StockPositionDataProviderInterface;
use App\Inventory\Domain\Entity\StockPosition;
use App\Inventory\Domain\Exception\StockOnHandBelowReservedException;
use App\Inventory\Domain\Exception\StockPositionNotFoundException;
use App\Inventory\Domain\Repository\StockPositionRepositoryInterface;
use App\Inventory\Domain\ValueObject\ProductId;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ChangeStockOnHandAction::class)]
final class ChangeStockOnHandActionTest extends TestCase
{
    public function testChangeStockOnHandSuccess(): void
    {
        $productId = new ProductId('prod-1');
        $position = new StockPosition($productId, onHand: 10, reserved: 2);

        $dataProvider = $this->createMock(StockPositionDataProviderInterface::class);
        $dataProvider->expects(self::once())
            ->method('findByProductId')
            ->with($productId, lock: true)
            ->willReturn($position);

        $repository = $this->createMock(StockPositionRepositoryInterface::class);
        $repository->expects(self::once())
            ->method('save')
            ->with($position);

        $action = new ChangeStockOnHandAction($dataProvider, $repository);
        $result = $action($productId, 20);

        self::assertSame(20, $result->onHand());
        self::assertSame(2, $result->reserved());
        self::assertSame(18, $result->available());
    }

    public function testChangeStockOnHandPropagatesExceptionWhenNotFound(): void
    {
        $productId = new ProductId('prod-missing');

        $dataProvider = $this->createMock(StockPositionDataProviderInterface::class);
        $dataProvider->expects(self::once())
            ->method('findByProductId')
            ->with($productId, lock: true)
            ->willThrowException(new StockPositionNotFoundException('prod-missing'));

        $repository = $this->createMock(StockPositionRepositoryInterface::class);
        $repository->expects(self::never())->method('save');

        $action = new ChangeStockOnHandAction($dataProvider, $repository);

        $this->expectException(StockPositionNotFoundException::class);
        $action($productId, 10);
    }

    public function testChangeStockOnHandThrowsWhenBelowReserved(): void
    {
        $productId = new ProductId('prod-1');
        $position = new StockPosition($productId, onHand: 10, reserved: 5);

        $dataProvider = $this->createMock(StockPositionDataProviderInterface::class);
        $dataProvider->expects(self::once())
            ->method('findByProductId')
            ->with($productId, lock: true)
            ->willReturn($position);

        $repository = $this->createMock(StockPositionRepositoryInterface::class);
        $repository->expects(self::never())->method('save');

        $action = new ChangeStockOnHandAction($dataProvider, $repository);

        $this->expectException(StockOnHandBelowReservedException::class);
        $action($productId, 3);
    }
}
