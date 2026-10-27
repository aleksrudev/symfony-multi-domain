<?php

declare(strict_types=1);

namespace Tests\Unit\Inventory\Application\Query\GetStockPosition;

use App\Inventory\Application\Query\GetStockPosition\GetStockPositionHandler;
use App\Inventory\Application\Query\GetStockPosition\GetStockPositionQuery;
use App\Inventory\Application\Query\GetStockPosition\Result\GetStockPositionQueryResult;
use App\Inventory\Domain\DataProvider\StockPositionDataProviderInterface;
use App\Inventory\Domain\Entity\StockPosition;
use App\Inventory\Domain\Exception\StockPositionNotFoundException;
use App\Inventory\Domain\ValueObject\ProductId;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(GetStockPositionHandler::class)]
#[CoversClass(GetStockPositionQueryResult::class)]
final class GetStockPositionHandlerTest extends TestCase
{
    public function testHandleSuccess(): void
    {
        $productId = new ProductId('prod-1');
        $position = new StockPosition($productId, onHand: 100, reserved: 20);

        $dataProvider = $this->createMock(StockPositionDataProviderInterface::class);
        $dataProvider->expects(self::once())
            ->method('findByProductId')
            ->with($productId)
            ->willReturn($position);

        $handler = new GetStockPositionHandler($dataProvider);
        $result = $handler(new GetStockPositionQuery('prod-1'));

        self::assertSame('prod-1', $result->productId);
        self::assertSame(100, $result->onHand);
        self::assertSame(20, $result->reserved);
        self::assertSame(80, $result->available);
        self::assertSame($position->updatedAt()->format(\DateTimeInterface::ATOM), $result->updatedAt);
    }

    public function testHandleInvalidProductIdThrowsNotFound(): void
    {
        $dataProvider = $this->createMock(StockPositionDataProviderInterface::class);
        $dataProvider->expects(self::never())->method('findByProductId');

        $handler = new GetStockPositionHandler($dataProvider);

        $this->expectException(StockPositionNotFoundException::class);
        $handler(new GetStockPositionQuery(''));
    }
}
