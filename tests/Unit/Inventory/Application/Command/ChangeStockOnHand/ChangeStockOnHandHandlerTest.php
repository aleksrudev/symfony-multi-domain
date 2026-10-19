<?php

declare(strict_types=1);

namespace Tests\Unit\Inventory\Application\Command\ChangeStockOnHand;

use App\Inventory\Application\Command\ChangeStockOnHand\ChangeStockOnHandCommand;
use App\Inventory\Application\Command\ChangeStockOnHand\ChangeStockOnHandHandler;
use App\Inventory\Domain\Action\ChangeStockOnHandAction;
use App\Inventory\Domain\DataProvider\StockPositionDataProviderInterface;
use App\Inventory\Domain\Entity\StockPosition;
use App\Inventory\Domain\Exception\StockPositionNotFoundException;
use App\Inventory\Domain\Repository\StockPositionRepositoryInterface;
use App\Inventory\Domain\ValueObject\ProductId;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ChangeStockOnHandHandler::class)]
final class ChangeStockOnHandHandlerTest extends TestCase
{
    public function testHandleSuccess(): void
    {
        $productId = new ProductId('prod-1');
        $position = new StockPosition($productId, onHand: 10, reserved: 2);

        $dataProvider = $this->createMock(StockPositionDataProviderInterface::class);
        $dataProvider->expects(self::once())
            ->method('findByProductId')
            ->with(self::callback(fn (ProductId $id): bool => 'prod-1' === $id->value), true)
            ->willReturn($position);

        $repository = $this->createMock(StockPositionRepositoryInterface::class);
        $repository->expects(self::once())
            ->method('save')
            ->with($position);

        $action = new ChangeStockOnHandAction($dataProvider, $repository);
        $handler = new ChangeStockOnHandHandler($action);

        $handler(new ChangeStockOnHandCommand('prod-1', 50));

        self::assertSame(50, $position->onHand());
        self::assertSame(2, $position->reserved());
        self::assertSame(48, $position->available());
    }

    public function testHandleInvalidProductIdThrowsNotFound(): void
    {
        $dataProvider = $this->createMock(StockPositionDataProviderInterface::class);
        $dataProvider->expects(self::never())->method('findByProductId');

        $repository = $this->createMock(StockPositionRepositoryInterface::class);
        $repository->expects(self::never())->method('save');

        $action = new ChangeStockOnHandAction($dataProvider, $repository);
        $handler = new ChangeStockOnHandHandler($action);

        $this->expectException(StockPositionNotFoundException::class);
        $handler(new ChangeStockOnHandCommand('   ', 50));
    }
}

