<?php

declare(strict_types=1);

namespace App\Ordering\Presentation\Contracts\Dto;

final readonly class SubmittedOrderItem
{
    public function __construct(
        public string $productId,
        public int $quantity,
        public string $productName,
        public int $priceAmount,
    ) {
    }
}

