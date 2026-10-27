<?php

declare(strict_types=1);

namespace App\Inventory\Domain\Collection;

use App\Inventory\Domain\Entity\ReservationItem;
use App\Shared\Domain\Collection\AbstractCollection;

/**
 * @extends AbstractCollection<ReservationItem>
 */
final class ReservationItemCollection extends AbstractCollection
{
    protected function elementClass(): string
    {
        return ReservationItem::class;
    }

    /**
     * Returns a new collection with items sorted by productId ascending.
     */
    public function sortedByProductId(): self
    {
        $items = $this->all();
        usort($items, static fn (ReservationItem $a, ReservationItem $b): int => strcmp(
            $a->productId()->value,
            $b->productId()->value,
        ));

        return new self($items);
    }
}
