<?php

declare(strict_types=1);

namespace App\Ordering\Domain\Collection;

use App\Ordering\Domain\Entity\OrderItem;
use App\Shared\Domain\Collection\AbstractCollection;

/**
 * @extends AbstractCollection<OrderItem>
 */
final class OrderItemCollection extends AbstractCollection
{
    protected function elementClass(): string
    {
        return OrderItem::class;
    }
}
