<?php

declare(strict_types=1);

namespace App\Inventory\Domain\Enum;

enum ReservationStatus: string
{
    case PENDING = 'pending';
    case RESERVED = 'reserved';
    case REJECTED = 'rejected';
    case RELEASED = 'released';

    public function isFinal(): bool
    {
        return self::REJECTED === $this || self::RELEASED === $this;
    }
}
