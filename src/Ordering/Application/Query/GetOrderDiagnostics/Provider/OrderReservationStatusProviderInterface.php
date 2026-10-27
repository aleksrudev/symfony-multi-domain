<?php

declare(strict_types=1);

namespace App\Ordering\Application\Query\GetOrderDiagnostics\Provider;

interface OrderReservationStatusProviderInterface
{
    public function getReservationStatus(string $orderId): OrderReservationStatus;
}
