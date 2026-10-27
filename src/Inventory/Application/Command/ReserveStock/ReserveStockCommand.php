<?php

declare(strict_types=1);

namespace App\Inventory\Application\Command\ReserveStock;

use App\Inventory\Application\Command\ReserveStock\Input\ReservationItemInput;
use App\Shared\Application\Bus\Command\CommandInterface;

final readonly class ReserveStockCommand implements CommandInterface
{
    /**
     * @param list<ReservationItemInput> $items
     */
    public function __construct(
        public string $orderId,
        public array $items,
    ) {
    }
}
