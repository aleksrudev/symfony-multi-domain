<?php

declare(strict_types=1);

namespace App\Ordering\Application\Query\GetOrderDiagnostics\Provider;

final readonly class OrderReservationStatus
{
    public function __construct(
        public string $orderId,
        public string $status,
        public ?string $rejectionReason = null,
    ) {
    }
}
