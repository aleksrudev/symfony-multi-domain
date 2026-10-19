<?php

declare(strict_types=1);

namespace App\Inventory\Application\Command\ChangeStockOnHand;

use App\Shared\Application\Bus\Command\CommandInterface;

final readonly class ChangeStockOnHandCommand implements CommandInterface
{
    public function __construct(
        public string $productId,
        public int $onHand,
    ) {
    }
}

