<?php

declare(strict_types=1);

namespace App\Inventory\Application\Query\GetStockPosition\Result;

final readonly class GetStockPositionQueryResult
{
    public function __construct(
        public string $productId,
        public int $onHand,
        public int $reserved,
        public int $available,
        public string $updatedAt,
    ) {
    }
}
