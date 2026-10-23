<?php

declare(strict_types=1);

namespace App\Inventory\Application\Query\GetReservation;

final readonly class GetReservationQueryResult
{
    /**
     * @param list<array{productId: string, quantity: int}> $items
     */
    public function __construct(
        public string $orderId,
        public string $status,
        public ?string $rejectionReason,
        public array $items,
        public string $updatedAt,
    ) {
    }
}

