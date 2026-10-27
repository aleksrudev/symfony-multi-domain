<?php

declare(strict_types=1);

namespace App\Catalog\Domain\Collection;

final readonly class ProductPaginatedCollection
{
    public function __construct(
        public ProductCollection $items,
        public int $count,
    ) {
    }
}
