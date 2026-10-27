<?php

declare(strict_types=1);

namespace App\Catalog\Application\Query\GetProduct\Result;

final readonly class GetProductQueryResult
{
    public function __construct(
        public string $productId,
        public string $name,
        public int $priceAmount,
        public string $currency,
        public bool $published,
    ) {
    }
}

