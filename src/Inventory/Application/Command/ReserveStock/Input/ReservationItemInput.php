<?php

declare(strict_types=1);

namespace App\Inventory\Application\Command\ReserveStock\Input;

final readonly class ReservationItemInput
{
    public function __construct(
        public string $productId,
        public int $quantity,
    ) {
        if ($quantity <= 0) {
            throw new \InvalidArgumentException('Quantity must be greater than zero.');
        }
    }
}

