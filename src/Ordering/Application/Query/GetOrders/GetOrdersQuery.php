<?php

declare(strict_types=1);

namespace App\Ordering\Application\Query\GetOrders;

use App\Shared\Application\Bus\Query\QueryInterface;

final readonly class GetOrdersQuery implements QueryInterface
{
    public function __construct(
        public string $buyerId,
        public int $page = 1,
        public int $limit = 10,
    ) {
    }
}
