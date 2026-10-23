<?php

declare(strict_types=1);

namespace Tests\Unit\Inventory\Application\Message;

use App\Inventory\Application\Command\ReleaseStock\ReleaseStockCommand;
use App\Inventory\Application\Message\ReleaseStockInboxHandler;
use App\Inventory\Application\Message\ReleaseStockInboxMessage;
use App\Shared\Application\Bus\Command\CommandBusInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ReleaseStockInboxHandler::class)]
final class ReleaseStockInboxHandlerTest extends TestCase
{
    public function testHandlerDispatchesReleaseStockCommand(): void
    {
        $commandBus = $this->createMock(CommandBusInterface::class);
        $handler = new ReleaseStockInboxHandler($commandBus);

        $orderId = '00000000-0000-4000-8000-000000000001';
        $message = new ReleaseStockInboxMessage(orderId: $orderId);

        $commandBus
            ->expects(self::once())
            ->method('dispatch')
            ->with(self::callback(static fn (ReleaseStockCommand $cmd): bool => $cmd->orderId === $orderId));

        $handler($message);
    }
}

