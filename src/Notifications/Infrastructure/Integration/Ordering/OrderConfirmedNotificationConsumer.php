<?php

declare(strict_types=1);

namespace App\Notifications\Infrastructure\Integration\Ordering;

use App\Notifications\Application\Command\EnqueueNotification\EnqueueNotificationCommand;
use App\Ordering\Presentation\Contracts\Event\OrderConfirmedEvent;
use App\Shared\Application\Bus\Command\CommandBusInterface;
use App\Shared\Infrastructure\Messaging\IdempotentConsumer\Consumer\IdempotentConsumerInterface;
use App\Shared\Infrastructure\Messaging\IdempotentConsumer\Identity\MessageIdentity;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler(bus: 'event.bus')]
final readonly class OrderConfirmedNotificationConsumer
{
    public function __construct(
        private IdempotentConsumerInterface $consumer,
        private CommandBusInterface $commandBus,
    ) {
    }

    public function __invoke(OrderConfirmedEvent $event): void
    {
        $this->consumer->consume(
            'notifications.order-confirmed',
            new MessageIdentity($event->source, $event->eventId),
            function () use ($event): void {
                $this->commandBus->dispatch(new EnqueueNotificationCommand(
                    orderId: $event->orderId,
                    type: 'order_confirmed',
                    buyerId: $event->buyerId,
                    payload: [
                        'totalAmount' => $event->totalAmount,
                        'currency' => $event->currency,
                    ],
                ));
            },
        );
    }
}

