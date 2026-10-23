<?php

declare(strict_types=1);

namespace Tests\Unit\Inventory\Application\Command\ReleaseStock;

use App\Inventory\Application\Command\ReleaseStock\ReleaseStockCommand;
use App\Inventory\Application\Command\ReleaseStock\ReleaseStockHandler;
use App\Inventory\Domain\Action\ReleaseStockAction;
use App\Inventory\Domain\Entity\Reservation;
use App\Inventory\Domain\ValueObject\OrderId;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ReleaseStockHandler::class)]
final class ReleaseStockHandlerTest extends TestCase
{
    public function testHandlerInvokesAction(): void
    {
        $action = $this->createMock(ReleaseStockAction::class);
        $handler = new ReleaseStockHandler($action);

        $orderIdStr = '00000000-0000-4000-8000-000000000001';
        $command = new ReleaseStockCommand($orderIdStr);

        $action
            ->expects(self::once())
            ->method('__invoke')
            ->with(self::callback(static fn (OrderId $id): bool => $id->value === $orderIdStr))
            ->willReturn(Reservation::createDefensiveReleased(new OrderId($orderIdStr)));

        $handler($command);
    }
}

