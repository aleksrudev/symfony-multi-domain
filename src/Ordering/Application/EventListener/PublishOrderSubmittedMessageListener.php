<?php

declare(strict_types=1);

namespace App\Ordering\Application\EventListener;

use App\Ordering\Application\Message\OrderSubmittedOutboxMessage;
use App\Ordering\Domain\Enum\Currency;
use App\Ordering\Domain\Event\OrderSubmittedEvent;
use App\Shared\Application\Bus\Outbox\OutboxBusInterface;

final readonly class PublishOrderSubmittedMessageListener
{
    public function __construct(
        private OutboxBusInterface $outboxBus,
    ) {
    }

    public function __invoke(OrderSubmittedEvent $event): void
    {
        $order = $event->order;

        $itemsPayload = [];
        foreach ($order->items() as $item) {
            $itemsPayload[] = [
                'productId' => $item->productId()->value,
                'quantity' => $item->quantity(),
            ];
        }

        $outboxMessage = new OrderSubmittedOutboxMessage(
            orderId: $order->id()->value,
            buyerId: $order->buyerId()->value,
            items: $itemsPayload,
            totalAmount: $order->totalAmount() ?? 0,
            currency: ($order->currency() ?? Currency::RUB)->value,
            occurredAt: $event->eventAt->format(\DateTimeInterface::ATOM),
        );

        $this->outboxBus->publish($outboxMessage);
    }
}
