<?php

declare(strict_types=1);

namespace Tests\Unit\Notifications\Infrastructure\Integration\Ordering;

use App\Notifications\Application\Command\EnqueueNotification\EnqueueNotificationCommand;
use App\Notifications\Infrastructure\Integration\Ordering\OrderRejectedNotificationConsumer;
use App\Ordering\Presentation\Contracts\Event\OrderRejectedEvent;
use App\Shared\Application\Bus\Command\CommandBusInterface;
use App\Shared\Infrastructure\Messaging\IdempotentConsumer\Consumer\IdempotentConsumerInterface;
use App\Shared\Infrastructure\Messaging\IdempotentConsumer\Identity\MessageIdentity;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(OrderRejectedNotificationConsumer::class)]
final class OrderRejectedNotificationConsumerTest extends TestCase
{
    public function testConsumerDispatchesEnqueueNotificationCommand(): void
    {
        $event = new OrderRejectedEvent(
            eventId: '018f0000-0000-7000-8000-000000000002',
            eventAt: '2026-10-27T12:00:00.000000Z',
            type: 'ordering.order_rejected',
            source: 'Ordering',
            schemaVersion: 1,
            orderId: '00000000-0000-4000-8000-000000000002',
            buyerId: 'buyer-2',
            reason: 'insufficient_stock',
        );

        $consumer = $this->createMock(IdempotentConsumerInterface::class);
        $commandBus = $this->createMock(CommandBusInterface::class);

        $consumer->expects(self::once())
            ->method('consume')
            ->with(
                'notifications.order-rejected',
                self::callback(static fn (MessageIdentity $id): bool => 'Ordering' === $id->namespace && '018f0000-0000-7000-8000-000000000002' === $id->id),
                self::callback(function (callable $operation) use ($commandBus): bool {
                    $commandBus->expects($this->once())
                        ->method('dispatch')
                        ->with(self::callback(static function (object $command): bool {
                            if (!$command instanceof EnqueueNotificationCommand) {
                                return false;
                            }

                            return '00000000-0000-4000-8000-000000000002' === $command->orderId
                                && 'order_rejected' === $command->type
                                && 'buyer-2' === $command->buyerId
                                && 'insufficient_stock' === ($command->payload['reason'] ?? null);
                        }));

                    $operation();

                    return true;
                }),
            );

        $handler = new OrderRejectedNotificationConsumer($consumer, $commandBus);
        $handler($event);
    }
}
