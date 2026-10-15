<?php

declare(strict_types=1);

namespace App\Catalog\Application\Query\GetProduct;

use App\Shared\Application\Bus\Query\QueryInterface;

final readonly class GetProductQuery implements QueryInterface
{
    public function __construct(
        public string $productId,
        public bool $onlyPublished = true,
    ) {
    }
}

