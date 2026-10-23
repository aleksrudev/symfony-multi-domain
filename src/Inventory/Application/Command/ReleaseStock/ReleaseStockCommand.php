<?php

declare(strict_types=1);

namespace App\Inventory\Application\Command\ReleaseStock;

use App\Shared\Application\Bus\Command\CommandInterface;

final readonly class ReleaseStockCommand implements CommandInterface
{
    public function __construct(
        public string $orderId,
    ) {
    }
}

