<?php

declare(strict_types=1);

namespace App\Inventory\Application\Query\GetStockPosition;

use App\Shared\Application\Bus\Query\QueryInterface;

final readonly class GetStockPositionQuery implements QueryInterface
{
    public function __construct(
        public string $productId,
    ) {
    }
}

