<?php

declare(strict_types=1);

namespace App\Catalog\Presentation\Contracts\Dto;

final readonly class Offer
{
    public function __construct(
        public string $productId,
        public string $name,
        public int $priceAmount,
        public string $currency,
    ) {
    }
}
