<?php

declare(strict_types=1);

namespace App\Ordering\Application\Query\GetOrder;

use App\Shared\Application\Bus\Query\QueryInterface;

final readonly class GetOrderQuery implements QueryInterface
{
    public function __construct(
        public string $orderId,
        public string $buyerId,
    ) {
    }
}
