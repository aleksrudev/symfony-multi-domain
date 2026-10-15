<?php

declare(strict_types=1);

namespace App\Catalog\Application\Query\GetProducts;

use App\Shared\Application\Bus\Query\QueryInterface;

final readonly class GetProductsQuery implements QueryInterface
{
    public function __construct(
        public int $page = 1,
        public int $limit = 10,
    ) {
    }
}

