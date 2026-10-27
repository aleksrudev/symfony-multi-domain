<?php

declare(strict_types=1);

namespace App\Catalog\Domain\Collection;

use App\Catalog\Domain\Entity\Product;
use App\Shared\Domain\Collection\AbstractCollection;

/**
 * @extends AbstractCollection<Product>
 */
final class ProductCollection extends AbstractCollection
{
    protected function elementClass(): string
    {
        return Product::class;
    }
}
