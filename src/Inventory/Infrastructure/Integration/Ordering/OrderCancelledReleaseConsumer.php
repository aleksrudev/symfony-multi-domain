<?php

declare(strict_types=1);

namespace App\Inventory\Infrastructure\Integration\Ordering;

use App\Inventory\Application\Command\ReleaseStock\ReleaseStockCommand;
use App\Ordering\Presentation\Contracts\Event\OrderCancelledEvent;
use App\Shared\Application\Bus\Command\CommandBusInterface;
use App\Shared\Infrastructure\Messaging\IdempotentConsumer\Consumer\IdempotentConsumerInterface;
use App\Shared\Infrastructure\Messaging\IdempotentConsumer\Identity\MessageIdentity;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler(bus: 'event.bus')]
final readonly class OrderCancelledReleaseConsumer
{
    public function __construct(
        private IdempotentConsumerInterface $consumer,
        private CommandBusInterface $commandBus,
    ) {
    }

    public function __invoke(OrderCancelledEvent $event): void
    {
        $this->consumer->consume(
            'inventory.release-stock-on-order-cancelled',
            new MessageIdentity($event->source, $event->eventId),
            function () use ($event): void {
                $this->commandBus->dispatch(new ReleaseStockCommand(
                    orderId: $event->orderId,
                ));
            },
        );
    }
}
