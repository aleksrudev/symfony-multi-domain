<?php

declare(strict_types=1);

namespace App\Ordering\Domain\Collection;

final readonly class OrderPaginatedCollection
{
    public function __construct(
        public OrderCollection $items,
        public int $count,
    ) {
    }
}
