<?php

declare(strict_types=1);

namespace App\Inventory\Presentation\Contracts;

use App\Inventory\Presentation\Contracts\Dto\ReservationResult;

interface InventoryContractInterface
{
    public function getReservationResult(string $orderId): ReservationResult;
}
