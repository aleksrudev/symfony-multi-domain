<?php

declare(strict_types=1);

namespace App\Inventory\Presentation\Contracts;

use App\Inventory\Presentation\Contracts\Dto\ReleaseRequest;
use App\Inventory\Presentation\Contracts\Dto\ReservationRequest;
use App\Inventory\Presentation\Contracts\Dto\ReservationResult;

interface InventoryContractInterface
{
    public function requestReservation(ReservationRequest $request): void;

    public function requestRelease(ReleaseRequest $request): void;

    public function getReservationResult(string $orderId): ReservationResult;
}

