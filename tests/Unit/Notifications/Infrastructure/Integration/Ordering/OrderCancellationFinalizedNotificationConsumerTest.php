<?php

declare(strict_types=1);

namespace Tests\Unit\Notifications\Infrastructure\Integration\Ordering;

use App\Notifications\Application\Command\EnqueueNotification\EnqueueNotificationCommand;
use App\Notifications\Infrastructure\Integration\Ordering\OrderCancellationFinalizedNotificationConsumer;
use App\Ordering\Presentation\Contracts\Event\OrderCancellationFinalizedEvent;
use App\Shared\Application\Bus\Command\CommandBusInterface;
use App\Shared\Infrastructure\Messaging\IdempotentConsumer\Consumer\IdempotentConsumerInterface;
use App\Shared\Infrastructure\Messaging\IdempotentConsumer\Identity\MessageIdentity;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(OrderCancellationFinalizedNotificationConsumer::class)]
final class OrderCancellationFinalizedNotificationConsumerTest extends TestCase
{
    public function testConsumerDispatchesEnqueueNotificationCommand(): void
    {
        $event = new OrderCancellationFinalizedEvent(
            eventId: '018f0000-0000-7000-8000-000000000003',
            eventAt: '2026-10-27T12:00:00.000000Z',
            type: 'ordering.order_cancellation_finalized',
            source: 'Ordering',
            schemaVersion: 1,
            orderId: '00000000-0000-4000-8000-000000000003',
            buyerId: 'buyer-3',
        );

        $consumer = $this->createMock(IdempotentConsumerInterface::class);
        $commandBus = $this->createMock(CommandBusInterface::class);

        $consumer->expects(self::once())
            ->method('consume')
            ->with(
                'notifications.order-cancellation-finalized',
                self::callback(static fn (MessageIdentity $id): bool => 'Ordering' === $id->namespace && '018f0000-0000-7000-8000-000000000003' === $id->id),
                self::callback(function (callable $operation) use ($commandBus): bool {
                    $commandBus->expects($this->once())
                        ->method('dispatch')
                        ->with(self::callback(static function (object $command): bool {
                            if (!$command instanceof EnqueueNotificationCommand) {
                                return false;
                            }

                            return '00000000-0000-4000-8000-000000000003' === $command->orderId
                                && 'order_cancelled' === $command->type
                                && 'buyer-3' === $command->buyerId;
                        }));

                    $operation();

                    return true;
                }),
            );

        $handler = new OrderCancellationFinalizedNotificationConsumer($consumer, $commandBus);
        $handler($event);
    }
}
