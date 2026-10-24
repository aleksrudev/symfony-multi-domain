<?php

declare(strict_types=1);

namespace App\Inventory\Application\Command\RequestReservation;

interface ReservationQueuePortInterface
{
    /**
     * @param list<RequestReservationItemInput> $items
     */
    public function enqueue(string $orderId, array $items): void;
}
