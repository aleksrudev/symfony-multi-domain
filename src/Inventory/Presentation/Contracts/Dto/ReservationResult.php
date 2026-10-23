<?php

declare(strict_types=1);

namespace App\Inventory\Presentation\Contracts\Dto;

final readonly class ReservationResult
{
    /**
     * @param list<ReservationResultItem> $items
     */
    public function __construct(
        public string $orderId,
        public string $status,
        public ?string $rejectionReason = null,
        public array $items = [],
    ) {
    }
}

