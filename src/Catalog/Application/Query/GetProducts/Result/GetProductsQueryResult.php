<?php

declare(strict_types=1);

namespace App\Catalog\Application\Query\GetProducts\Result;

use App\Catalog\Application\Query\GetProduct\Result\GetProductQueryResult;

final readonly class GetProductsQueryResult
{
    /**
     * @param list<GetProductQueryResult> $items
     */
    public function __construct(
        public array $items,
        public int $total,
        public int $page,
        public int $limit,
    ) {
    }
}

