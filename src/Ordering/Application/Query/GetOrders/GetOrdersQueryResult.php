<?php

declare(strict_types=1);

namespace App\Ordering\Application\Query\GetOrders;

use App\Ordering\Application\Query\GetOrder\GetOrderQueryResult;

final readonly class GetOrdersQueryResult
{
    /**
     * @param list<GetOrderQueryResult> $items
     */
    public function __construct(
        public array $items,
        public int $total,
        public int $page,
        public int $limit,
    ) {
    }
}
