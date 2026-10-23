<?php

declare(strict_types=1);

namespace App\Ordering\Infrastructure\Integration\Inventory;

use App\Inventory\Presentation\Contracts\Dto\ReservationItemRequest;
use App\Inventory\Presentation\Contracts\Dto\ReservationRequest;
use App\Inventory\Presentation\Contracts\InventoryContractInterface;
use App\Ordering\Presentation\Contracts\Event\OrderSubmittedEvent;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

#[AsEventListener(event: OrderSubmittedEvent::class)]
final readonly class OrderSubmittedInventoryListener
{
    public function __construct(
        private InventoryContractInterface $inventoryContract,
    ) {
    }

    public function __invoke(OrderSubmittedEvent $event): void
    {
        $items = [];
        foreach ($event->items as $item) {
            $items[] = new ReservationItemRequest(
                productId: $item->productId,
                quantity: $item->quantity,
            );
        }

        $this->inventoryContract->requestReservation(new ReservationRequest(
            orderId: $event->orderId,
            items: $items,
        ));
    }
}

