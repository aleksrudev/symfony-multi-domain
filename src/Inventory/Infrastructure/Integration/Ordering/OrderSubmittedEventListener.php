<?php

declare(strict_types=1);

namespace App\Inventory\Infrastructure\Integration\Ordering;

use App\Inventory\Infrastructure\Messaging\Inbox\ReserveStock\ReserveStockInboxItem;
use App\Inventory\Infrastructure\Messaging\Inbox\ReserveStock\ReserveStockInboxMessage;
use App\Ordering\Presentation\Contracts\Event\OrderSubmittedEvent;
use App\Shared\Application\Bus\Inbox\InboxBusInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

#[AsEventListener(event: OrderSubmittedEvent::class)]
final readonly class OrderSubmittedEventListener
{
    public function __construct(
        private InboxBusInterface $inboxBus,
    ) {
    }

    public function __invoke(OrderSubmittedEvent $event): void
    {
        $items = [];
        foreach ($event->items as $item) {
            $items[] = new ReserveStockInboxItem(
                productId: $item->productId,
                quantity: $item->quantity,
            );
        }

        $this->inboxBus->dispatch(new ReserveStockInboxMessage(
            orderId: $event->orderId,
            items: $items,
        ));
    }
}
