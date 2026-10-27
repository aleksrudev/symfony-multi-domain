<?php

declare(strict_types=1);

namespace Tests\Unit\Inventory\Application\Command\ReserveStock;

use App\Inventory\Application\Command\ReserveStock\Input\ReservationItemInput;
use App\Inventory\Application\Command\ReserveStock\ReserveStockCommand;
use App\Inventory\Application\Command\ReserveStock\ReserveStockHandler;
use App\Inventory\Domain\Action\ReserveStockAction;
use App\Inventory\Domain\DataProvider\ReservationDataProviderInterface;
use App\Inventory\Domain\DataProvider\StockPositionDataProviderInterface;
use App\Inventory\Domain\Entity\StockPosition;
use App\Inventory\Domain\Exception\ReservationNotFoundException;
use App\Inventory\Domain\Repository\ReservationRepositoryInterface;
use App\Inventory\Domain\Repository\StockPositionRepositoryInterface;
use App\Inventory\Domain\ValueObject\ProductId;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ReserveStockHandler::class)]
final class ReserveStockHandlerTest extends TestCase
{
    public function testHandlerInvokesAction(): void
    {
        $stockPositionDataProvider = $this->createMock(StockPositionDataProviderInterface::class);
        $stockPositionRepository = $this->createMock(StockPositionRepositoryInterface::class);
        $reservationDataProvider = $this->createMock(ReservationDataProviderInterface::class);
        $reservationRepository = $this->createMock(ReservationRepositoryInterface::class);

        $orderIdStr = '00000000-0000-4000-8000-000000000001';
        $pos1 = new StockPosition(new ProductId('prod-1'), 10, 0);
        $pos2 = new StockPosition(new ProductId('prod-2'), 10, 0);

        $reservationDataProvider
            ->method('findByOrderId')
            ->willThrowException(new ReservationNotFoundException($orderIdStr));

        $stockPositionDataProvider
            ->method('findByProductId')
            ->willReturnCallback(static fn (ProductId $id): StockPosition => match ($id->value) {
                'prod-1' => $pos1,
                'prod-2' => $pos2,
                default => throw new \InvalidArgumentException('Unexpected: '.$id->value),
            });

        $reservationRepository
            ->expects(self::once())
            ->method('save');

        $action = new ReserveStockAction(
            $stockPositionDataProvider,
            $stockPositionRepository,
            $reservationDataProvider,
            $reservationRepository,
        );
        $handler = new ReserveStockHandler($action);

        $command = new ReserveStockCommand(
            $orderIdStr,
            [
                new ReservationItemInput('prod-1', 2),
                new ReservationItemInput('prod-2', 5),
            ],
        );

        $handler($command);
    }
}
