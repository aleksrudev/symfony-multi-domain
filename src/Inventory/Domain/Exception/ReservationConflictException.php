<?php

declare(strict_types=1);

namespace App\Inventory\Domain\Exception;

use App\Shared\Domain\Exception\DomainException;

final class ReservationConflictException extends DomainException
{
    public function __construct(string $orderId)
    {
        parent::__construct(sprintf(
            'Reservation for order "%s" already exists with different items or conflicting state.',
            $orderId,
        ));
    }
}
