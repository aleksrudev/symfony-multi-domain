<?php

declare(strict_types=1);

namespace Tests\Unit\Inventory\Infrastructure\Integration\Ordering;

use App\Inventory\Application\Command\ReserveStock\ReserveStockCommand;
use App\Inventory\Infrastructure\Integration\Ordering\OrderSubmittedReservationConsumer;
use App\Ordering\Presentation\Contracts\Dto\SubmittedOrderItem;
use App\Ordering\Presentation\Contracts\Event\OrderSubmittedEvent;
use App\Shared\Application\Bus\Command\CommandBusInterface;
use App\Shared\Infrastructure\Messaging\IdempotentConsumer\Consumer\IdempotentConsumerInterface;
use App\Shared\Infrastructure\Messaging\IdempotentConsumer\Identity\MessageIdentity;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(OrderSubmittedReservationConsumer::class)]
final class OrderSubmittedReservationConsumerTest extends TestCase
{
    public function testConsumerProcessesEventThroughIdempotentConsumer(): void
    {
        $event = new OrderSubmittedEvent(
            eventId: '018f0000-0000-7000-8000-000000000001',
            eventAt: '2026-10-26T12:00:00.000000Z',
            type: 'ordering.order_submitted',
            source: 'Ordering',
            schemaVersion: 1,
            orderId: '00000000-0000-4000-8000-000000000001',
            buyerId: 'buyer-1',
            items: [
                new SubmittedOrderItem('prod-1', 2, 'Product 1', 10000),
            ],
            totalAmount: 20000,
            currency: 'RUB',
        );

        $consumer = $this->createMock(IdempotentConsumerInterface::class);
        $commandBus = $this->createMock(CommandBusInterface::class);

        $consumer->expects(self::once())
            ->method('consume')
            ->with(
                'inventory.reserve-stock-on-order-submitted',
                self::callback(static fn (MessageIdentity $id): bool => 'Ordering' === $id->namespace && '018f0000-0000-7000-8000-000000000001' === $id->id),
                self::callback(function (callable $operation) use ($commandBus): bool {
                    $commandBus->expects($this->once())
                        ->method('dispatch')
                        ->with(self::callback(static function (object $command): bool {
                            if (!$command instanceof ReserveStockCommand) {
                                return false;
                            }

                            return '00000000-0000-4000-8000-000000000001' === $command->orderId
                                && 1 === count($command->items)
                                && 'prod-1' === $command->items[0]->productId
                                && 2 === $command->items[0]->quantity;
                        }));

                    $operation();

                    return true;
                }),
            );

        $handler = new OrderSubmittedReservationConsumer($consumer, $commandBus);
        $handler($event);
    }
}
