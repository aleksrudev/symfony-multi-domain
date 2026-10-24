<?php

declare(strict_types=1);

namespace App\Ordering\Infrastructure\Messaging\Outbox\RequestReservation;

final readonly class RequestReservationOutboxItem
{
    public function __construct(
        public string $productId,
        public int $quantity,
    ) {
    }
}
