<?php

declare(strict_types=1);

namespace App\Ordering\Application\Command\SubmitOrder;

final readonly class OfferSnapshot
{
    public function __construct(
        public string $productId,
        public string $name,
        public int $priceAmount,
        public string $currency,
    ) {
    }
}

