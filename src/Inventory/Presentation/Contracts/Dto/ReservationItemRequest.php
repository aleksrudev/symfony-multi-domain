<?php

declare(strict_types=1);

namespace App\Inventory\Presentation\Contracts\Dto;

final readonly class ReservationItemRequest
{
    public function __construct(
        public string $productId,
        public int $quantity,
    ) {
    }
}

