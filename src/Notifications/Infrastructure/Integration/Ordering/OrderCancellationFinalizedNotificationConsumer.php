<?php

declare(strict_types=1);

namespace App\Notifications\Infrastructure\Integration\Ordering;

use App\Notifications\Application\Command\EnqueueNotification\EnqueueNotificationCommand;
use App\Ordering\Presentation\Contracts\Event\OrderCancellationFinalizedEvent;
use App\Shared\Application\Bus\Command\CommandBusInterface;
use App\Shared\Infrastructure\Messaging\IdempotentConsumer\Consumer\IdempotentConsumerInterface;
use App\Shared\Infrastructure\Messaging\IdempotentConsumer\Identity\MessageIdentity;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler(bus: 'event.bus')]
final readonly class OrderCancellationFinalizedNotificationConsumer
{
    public function __construct(
        private IdempotentConsumerInterface $consumer,
        private CommandBusInterface $commandBus,
    ) {
    }

    public function __invoke(OrderCancellationFinalizedEvent $event): void
    {
        $this->consumer->consume(
            'notifications.order-cancellation-finalized',
            new MessageIdentity($event->source, $event->eventId),
            function () use ($event): void {
                $this->commandBus->dispatch(new EnqueueNotificationCommand(
                    orderId: $event->orderId,
                    type: 'order_cancelled',
                    buyerId: $event->buyerId,
                    payload: [],
                ));
            },
        );
    }
}
