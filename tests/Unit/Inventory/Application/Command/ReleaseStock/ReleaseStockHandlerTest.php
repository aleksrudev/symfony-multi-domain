<?php

declare(strict_types=1);

namespace Tests\Unit\Inventory\Application\Command\ReleaseStock;

use App\Inventory\Application\Command\ReleaseStock\ReleaseStockCommand;
use App\Inventory\Application\Command\ReleaseStock\ReleaseStockHandler;
use App\Inventory\Domain\Action\ReleaseStockAction;
use App\Inventory\Domain\DataProvider\ReservationDataProviderInterface;
use App\Inventory\Domain\DataProvider\StockPositionDataProviderInterface;
use App\Inventory\Domain\Exception\ReservationNotFoundException;
use App\Inventory\Domain\Repository\ReservationRepositoryInterface;
use App\Inventory\Domain\Repository\StockPositionRepositoryInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ReleaseStockHandler::class)]
final class ReleaseStockHandlerTest extends TestCase
{
    public function testHandlerInvokesAction(): void
    {
        $stockPositionDataProvider = $this->createMock(StockPositionDataProviderInterface::class);
        $stockPositionRepository = $this->createMock(StockPositionRepositoryInterface::class);
        $reservationDataProvider = $this->createMock(ReservationDataProviderInterface::class);
        $reservationRepository = $this->createMock(ReservationRepositoryInterface::class);

        $orderIdStr = '00000000-0000-4000-8000-000000000001';

        $reservationDataProvider
            ->method('findByOrderId')
            ->willThrowException(new ReservationNotFoundException($orderIdStr));

        $reservationRepository
            ->expects(self::once())
            ->method('save');

        $action = new ReleaseStockAction(
            $stockPositionDataProvider,
            $stockPositionRepository,
            $reservationDataProvider,
            $reservationRepository,
        );
        $handler = new ReleaseStockHandler($action);

        $command = new ReleaseStockCommand($orderIdStr);
        $handler($command);
    }
}
