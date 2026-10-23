<?php

declare(strict_types=1);

namespace App\Ordering\Domain\Collection;

use App\Ordering\Domain\ValueObject\ProductId;
use App\Ordering\Domain\ValueObject\ProductSnapshot;
use App\Shared\Domain\Collection\AbstractCollection;

/**
 * @extends AbstractCollection<ProductSnapshot>
 */
final class ProductSnapshotCollection extends AbstractCollection
{
    protected function elementClass(): string
    {
        return ProductSnapshot::class;
    }

    public function findByProductId(ProductId $productId): ?ProductSnapshot
    {
        foreach ($this->all() as $element) {
            if ($element->productId->equals($productId)) {
                return $element;
            }
        }

        return null;
    }
}
