<?php

declare(strict_types=1);

namespace App\Ordering\Infrastructure\Messaging\EventListener;

use App\Ordering\Domain\Event\OrderSubmittedEvent as DomainOrderSubmittedEvent;
use App\Ordering\Infrastructure\Messaging\Outbox\RequestReservation\RequestReservationOutboxItem;
use App\Ordering\Infrastructure\Messaging\Outbox\RequestReservation\RequestReservationOutboxMessage;
use App\Shared\Application\Bus\Outbox\OutboxBusInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

#[AsEventListener(event: DomainOrderSubmittedEvent::class)]
final readonly class OrderSubmittedReservationRequestListener
{
    public function __construct(
        private OutboxBusInterface $outboxBus,
    ) {
    }

    public function __invoke(DomainOrderSubmittedEvent $event): void
    {
        $order = $event->order;

        $items = [];
        foreach ($order->items() as $item) {
            $items[] = new RequestReservationOutboxItem(
                productId: $item->productId()->value,
                quantity: $item->quantity(),
            );
        }

        $this->outboxBus->publish(new RequestReservationOutboxMessage(
            orderId: $order->id()->value,
            items: $items,
        ));
    }
}
