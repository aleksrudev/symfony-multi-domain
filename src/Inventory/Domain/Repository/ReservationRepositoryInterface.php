<?php

declare(strict_types=1);

namespace App\Inventory\Domain\Repository;

use App\Inventory\Domain\Entity\Reservation;

interface ReservationRepositoryInterface
{
    public function save(Reservation $reservation): void;

    public function remove(Reservation $reservation): void;
}

