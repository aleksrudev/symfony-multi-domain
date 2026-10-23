<?php

declare(strict_types=1);

namespace App\Inventory\Presentation\Contracts\Dto;

final readonly class ReservationRequest
{
    /**
     * @param list<ReservationItemRequest> $items
     */
    public function __construct(
        public string $orderId,
        public array $items,
    ) {
    }
}

