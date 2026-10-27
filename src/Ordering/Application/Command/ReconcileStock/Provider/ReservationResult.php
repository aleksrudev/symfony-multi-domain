<?php

declare(strict_types=1);

namespace App\Ordering\Application\Command\ReconcileStock\Provider;

final readonly class ReservationResult
{
    public function __construct(
        public string $orderId,
        public string $status,
        public ?string $rejectionReason,
    ) {
    }
}
