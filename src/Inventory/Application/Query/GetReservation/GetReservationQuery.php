<?php

declare(strict_types=1);

namespace App\Inventory\Application\Query\GetReservation;

use App\Shared\Application\Bus\Query\QueryInterface;

final readonly class GetReservationQuery implements QueryInterface
{
    public function __construct(
        public string $orderId,
    ) {
    }
}
