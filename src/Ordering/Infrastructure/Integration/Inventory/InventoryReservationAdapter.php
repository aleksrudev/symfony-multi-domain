<?php

declare(strict_types=1);

namespace App\Ordering\Infrastructure\Integration\Inventory;

use App\Inventory\Presentation\Contracts\Dto\ReservationItemRequest;
use App\Inventory\Presentation\Contracts\Dto\ReservationRequest;
use App\Inventory\Presentation\Contracts\InventoryContractInterface;
use App\Ordering\Infrastructure\Messaging\Outbox\RequestReservation\InventoryReservationGatewayInterface;
use App\Ordering\Infrastructure\Messaging\Outbox\RequestReservation\RequestReservationOutboxItem;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

#[AsAlias(InventoryReservationGatewayInterface::class)]
final readonly class InventoryReservationAdapter implements InventoryReservationGatewayInterface
{
    public function __construct(
        private InventoryContractInterface $inventoryContract,
    ) {
    }

    /**
     * @param list<RequestReservationOutboxItem> $items
     */
    public function requestReservation(string $orderId, array $items): void
    {
        $reservationItems = [];
        foreach ($items as $item) {
            $reservationItems[] = new ReservationItemRequest(
                productId: $item->productId,
                quantity: $item->quantity,
            );
        }

        $this->inventoryContract->requestReservation(new ReservationRequest(
            orderId: $orderId,
            items: $reservationItems,
        ));
    }
}
