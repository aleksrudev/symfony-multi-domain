<?php

declare(strict_types=1);

namespace App\Ordering\Domain\ValueObject;

use App\Ordering\Domain\Enum\Currency;

final readonly class ProductSnapshot
{
    public function __construct(
        public ProductId $productId,
        public string $name,
        public int $priceAmount,
        public Currency $currency,
    ) {
        if ($priceAmount <= 0) {
            throw new \InvalidArgumentException('Product price amount must be positive.');
        }
    }
}
