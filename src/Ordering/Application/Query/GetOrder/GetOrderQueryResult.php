<?php

declare(strict_types=1);

namespace App\Ordering\Application\Query\GetOrder;

final readonly class GetOrderQueryResult
{
    /**
     * @param list<OrderItemQueryResult> $items
     */
    public function __construct(
        public string $id,
        public string $buyerId,
        public string $status,
        public array $items,
        public string $createdAt,
        public string $updatedAt,
        public ?int $totalAmount = null,
        public ?string $currency = null,
    ) {
    }
}
