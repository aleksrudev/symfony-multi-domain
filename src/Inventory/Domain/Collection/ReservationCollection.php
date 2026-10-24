<?php

declare(strict_types=1);

namespace App\Inventory\Domain\Collection;

use App\Inventory\Domain\Entity\Reservation;
use App\Shared\Domain\Collection\AbstractCollection;

/**
 * @extends AbstractCollection<Reservation>
 */
final class ReservationCollection extends AbstractCollection
{
    protected function elementClass(): string
    {
        return Reservation::class;
    }
}
