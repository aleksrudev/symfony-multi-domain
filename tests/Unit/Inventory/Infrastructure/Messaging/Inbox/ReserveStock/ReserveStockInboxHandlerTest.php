<?php

declare(strict_types=1);

namespace Tests\Unit\Inventory\Infrastructure\Messaging\Inbox\ReserveStock;

use App\Inventory\Application\Command\ReserveStock\ReserveStockCommand;
use App\Inventory\Infrastructure\Messaging\Inbox\ReserveStock\ReserveStockInboxHandler;
use App\Inventory\Infrastructure\Messaging\Inbox\ReserveStock\ReserveStockInboxItem;
use App\Inventory\Infrastructure\Messaging\Inbox\ReserveStock\ReserveStockInboxMessage;
use App\Shared\Application\Bus\Command\CommandBusInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ReserveStockInboxHandler::class)]
final class ReserveStockInboxHandlerTest extends TestCase
{
    public function testHandlerDispatchesReserveStockCommand(): void
    {
        $commandBus = $this->createMock(CommandBusInterface::class);
        $handler = new ReserveStockInboxHandler($commandBus);

        $orderId = '00000000-0000-4000-8000-000000000001';
        $message = new ReserveStockInboxMessage(
            orderId: $orderId,
            items: [
                new ReserveStockInboxItem('prod-1', 2),
                new ReserveStockInboxItem('prod-2', 5),
            ],
        );

        $commandBus
            ->expects(self::once())
            ->method('dispatch')
            ->with(self::callback(static function (ReserveStockCommand $cmd) use ($orderId): bool {
                return $cmd->orderId === $orderId
                    && 2 === count($cmd->items)
                    && 'prod-1' === $cmd->items[0]->productId
                    && 2 === $cmd->items[0]->quantity;
            }));

        $handler($message);
    }
}

