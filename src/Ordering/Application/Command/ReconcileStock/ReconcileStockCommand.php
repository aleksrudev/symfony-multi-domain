<?php

declare(strict_types=1);

namespace App\Ordering\Application\Command\ReconcileStock;

use App\Shared\Application\Bus\Command\CommandInterface;

final readonly class ReconcileStockCommand implements CommandInterface
{
    public function __construct(
        public int $batchSize = 50,
    ) {
    }
}
