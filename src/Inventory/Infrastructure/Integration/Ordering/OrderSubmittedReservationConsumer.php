<?php

declare(strict_types=1);

namespace App\Inventory\Infrastructure\Integration\Ordering;

use App\Inventory\Application\Command\ReserveStock\ReservationItemInput;
use App\Inventory\Application\Command\ReserveStock\ReserveStockCommand;
use App\Ordering\Presentation\Contracts\Dto\SubmittedOrderItem;
use App\Ordering\Presentation\Contracts\Event\OrderSubmittedEvent;
use App\Shared\Application\Bus\Command\CommandBusInterface;
use App\Shared\Infrastructure\Messaging\IdempotentConsumer\Consumer\IdempotentConsumerInterface;
use App\Shared\Infrastructure\Messaging\IdempotentConsumer\Identity\MessageIdentity;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler(bus: 'event.bus')]
final readonly class OrderSubmittedReservationConsumer
{
    public function __construct(
        private IdempotentConsumerInterface $consumer,
        private CommandBusInterface $commandBus,
    ) {
    }

    public function __invoke(OrderSubmittedEvent $event): void
    {
        $this->consumer->consume(
            'inventory.reserve-stock-on-order-submitted',
            new MessageIdentity($event->source, $event->eventId),
            function () use ($event): void {
                $items = array_map(
                    static fn (SubmittedOrderItem $item): ReservationItemInput => new ReservationItemInput(
                        productId: $item->productId,
                        quantity: $item->quantity,
                    ),
                    $event->items,
                );

                $this->commandBus->dispatch(new ReserveStockCommand(
                    orderId: $event->orderId,
                    items: $items,
                ));
            },
        );
    }
}
