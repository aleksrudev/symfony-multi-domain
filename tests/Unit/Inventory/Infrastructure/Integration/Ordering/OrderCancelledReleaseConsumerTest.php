<?php

declare(strict_types=1);

namespace Tests\Unit\Inventory\Infrastructure\Integration\Ordering;

use App\Inventory\Application\Command\ReleaseStock\ReleaseStockCommand;
use App\Inventory\Infrastructure\Integration\Ordering\OrderCancelledReleaseConsumer;
use App\Ordering\Presentation\Contracts\Event\OrderCancelledEvent;
use App\Shared\Application\Bus\Command\CommandBusInterface;
use App\Shared\Infrastructure\Messaging\IdempotentConsumer\Consumer\IdempotentConsumerInterface;
use App\Shared\Infrastructure\Messaging\IdempotentConsumer\Identity\MessageIdentity;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(OrderCancelledReleaseConsumer::class)]
final class OrderCancelledReleaseConsumerTest extends TestCase
{
    public function testConsumerProcessesCancelledEventThroughIdempotentConsumer(): void
    {
        $event = new OrderCancelledEvent(
            eventId: '018f0000-0000-7000-8000-000000000099',
            eventAt: '2026-10-26T12:00:00.000000Z',
            type: 'ordering.order_cancelled',
            source: 'Ordering',
            schemaVersion: 1,
            orderId: '00000000-0000-4000-8000-000000000099',
            buyerId: 'buyer-1',
        );

        $consumer = $this->createMock(IdempotentConsumerInterface::class);
        $commandBus = $this->createMock(CommandBusInterface::class);

        $consumer->expects(self::once())
            ->method('consume')
            ->with(
                'inventory.release-stock-on-order-cancelled',
                self::callback(static fn (MessageIdentity $id): bool => 'Ordering' === $id->namespace && '018f0000-0000-7000-8000-000000000099' === $id->id),
                self::callback(function (callable $operation) use ($commandBus): bool {
                    $commandBus->expects($this->once())
                        ->method('dispatch')
                        ->with(self::callback(static function (object $command): bool {
                            if (!$command instanceof ReleaseStockCommand) {
                                return false;
                            }

                            return '00000000-0000-4000-8000-000000000099' === $command->orderId;
                        }));

                    $operation();

                    return true;
                }),
            );

        $handler = new OrderCancelledReleaseConsumer($consumer, $commandBus);
        $handler($event);
    }
}
