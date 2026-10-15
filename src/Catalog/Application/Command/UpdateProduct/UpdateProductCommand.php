<?php

declare(strict_types=1);

namespace App\Catalog\Application\Command\UpdateProduct;

use App\Shared\Application\Bus\Command\CommandInterface;

final readonly class UpdateProductCommand implements CommandInterface
{
    public function __construct(
        public string $productId,
        public ?int $priceAmount = null,
        public ?bool $published = null,
    ) {
    }
}
