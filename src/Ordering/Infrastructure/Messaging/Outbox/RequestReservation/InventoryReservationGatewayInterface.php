<?php

declare(strict_types=1);

namespace App\Ordering\Infrastructure\Messaging\Outbox\RequestReservation;

interface InventoryReservationGatewayInterface
{
    /**
     * @param list<RequestReservationOutboxItem> $items
     */
    public function requestReservation(string $orderId, array $items): void;
}
