<?php

declare(strict_types=1);

namespace App\Inventory\Application\Command\RequestReservation;

final readonly class RequestReservationItemInput
{
    public function __construct(
        public string $productId,
        public int $quantity,
    ) {
    }
}
