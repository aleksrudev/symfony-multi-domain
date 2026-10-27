<?php

declare(strict_types=1);

namespace App\Notifications\Infrastructure\Integration\Ordering;

use App\Notifications\Application\Command\EnqueueNotification\EnqueueNotificationCommand;
use App\Ordering\Presentation\Contracts\Event\OrderRejectedEvent;
use App\Shared\Application\Bus\Command\CommandBusInterface;
use App\Shared\Infrastructure\Messaging\IdempotentConsumer\Consumer\IdempotentConsumerInterface;
use App\Shared\Infrastructure\Messaging\IdempotentConsumer\Identity\MessageIdentity;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler(bus: 'event.bus')]
final readonly class OrderRejectedNotificationConsumer
{
    public function __construct(
        private IdempotentConsumerInterface $consumer,
        private CommandBusInterface $commandBus,
    ) {
    }

    public function __invoke(OrderRejectedEvent $event): void
    {
        $this->consumer->consume(
            'notifications.order-rejected',
            new MessageIdentity($event->source, $event->eventId),
            function () use ($event): void {
                $this->commandBus->dispatch(new EnqueueNotificationCommand(
                    orderId: $event->orderId,
                    type: 'order_rejected',
                    buyerId: $event->buyerId,
                    payload: [
                        'reason' => $event->reason,
                    ],
                ));
            },
        );
    }
}

