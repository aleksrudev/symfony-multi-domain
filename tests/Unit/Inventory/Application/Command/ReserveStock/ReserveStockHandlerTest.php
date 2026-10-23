<?php

declare(strict_types=1);

namespace Tests\Unit\Inventory\Application\Command\ReserveStock;

use App\Inventory\Application\Command\ReserveStock\ReservationItemInput;
use App\Inventory\Application\Command\ReserveStock\ReserveStockCommand;
use App\Inventory\Application\Command\ReserveStock\ReserveStockHandler;
use App\Inventory\Domain\Action\ReserveStockAction;
use App\Inventory\Domain\Collection\ReservationItemCollection;
use App\Inventory\Domain\Entity\Reservation;
use App\Inventory\Domain\ValueObject\OrderId;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ReserveStockHandler::class)]
final class ReserveStockHandlerTest extends TestCase
{
    public function testHandlerInvokesAction(): void
    {
        $action = $this->createMock(ReserveStockAction::class);
        $handler = new ReserveStockHandler($action);

        $orderIdStr = '00000000-0000-4000-8000-000000000001';
        $command = new ReserveStockCommand(
            $orderIdStr,
            [
                new ReservationItemInput('prod-1', 2),
                new ReservationItemInput('prod-2', 5),
            ],
        );

        $action
            ->expects(self::once())
            ->method('__invoke')
            ->with(
                self::callback(static fn (OrderId $id): bool => $id->value === $orderIdStr),
                self::callback(static fn (ReservationItemCollection $items): bool => 2 === count($items)),
            )
            ->willReturn(Reservation::createReserved(new OrderId($orderIdStr), new ReservationItemCollection()));

        $handler($command);
    }
}

