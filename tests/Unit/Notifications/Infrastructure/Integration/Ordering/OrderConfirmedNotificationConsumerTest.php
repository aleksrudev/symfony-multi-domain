<?php

declare(strict_types=1);

namespace Tests\Unit\Notifications\Infrastructure\Integration\Ordering;

use App\Notifications\Application\Command\EnqueueNotification\EnqueueNotificationCommand;
use App\Notifications\Infrastructure\Integration\Ordering\OrderConfirmedNotificationConsumer;
use App\Ordering\Presentation\Contracts\Event\OrderConfirmedEvent;
use App\Shared\Application\Bus\Command\CommandBusInterface;
use App\Shared\Infrastructure\Messaging\IdempotentConsumer\Consumer\IdempotentConsumerInterface;
use App\Shared\Infrastructure\Messaging\IdempotentConsumer\Identity\MessageIdentity;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(OrderConfirmedNotificationConsumer::class)]
final class OrderConfirmedNotificationConsumerTest extends TestCase
{
    public function testConsumerDispatchesEnqueueNotificationCommand(): void
    {
        $event = new OrderConfirmedEvent(
            eventId: '018f0000-0000-7000-8000-000000000001',
            eventAt: '2026-10-27T12:00:00.000000Z',
            type: 'ordering.order_confirmed',
            source: 'Ordering',
            schemaVersion: 1,
            orderId: '00000000-0000-4000-8000-000000000001',
            buyerId: 'buyer-1',
            totalAmount: 20000,
            currency: 'RUB',
        );

        $consumer = $this->createMock(IdempotentConsumerInterface::class);
        $commandBus = $this->createMock(CommandBusInterface::class);

        $consumer->expects(self::once())
            ->method('consume')
            ->with(
                'notifications.order-confirmed',
                self::callback(static fn (MessageIdentity $id): bool => 'Ordering' === $id->namespace && '018f0000-0000-7000-8000-000000000001' === $id->id),
                self::callback(function (callable $operation) use ($commandBus): bool {
                    $commandBus->expects($this->once())
                        ->method('dispatch')
                        ->with(self::callback(static function (object $command): bool {
                            if (!$command instanceof EnqueueNotificationCommand) {
                                return false;
                            }

                            return '00000000-0000-4000-8000-000000000001' === $command->orderId
                                && 'order_confirmed' === $command->type
                                && 'buyer-1' === $command->buyerId
                                && 20000 === ($command->payload['totalAmount'] ?? null)
                                && 'RUB' === ($command->payload['currency'] ?? null);
                        }));

                    $operation();

                    return true;
                }),
            );

        $handler = new OrderConfirmedNotificationConsumer($consumer, $commandBus);
        $handler($event);
    }
}

