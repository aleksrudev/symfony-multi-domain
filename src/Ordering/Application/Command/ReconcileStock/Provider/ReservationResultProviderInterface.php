<?php

declare(strict_types=1);

namespace App\Ordering\Application\Command\ReconcileStock\Provider;

interface ReservationResultProviderInterface
{
    public function getReservationResult(string $orderId): ReservationResult;
}
