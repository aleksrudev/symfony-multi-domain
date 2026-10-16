<?php

declare(strict_types=1);

namespace App\Ordering\Domain\Collection;

use App\Ordering\Domain\Entity\Order;
use App\Shared\Domain\Collection\AbstractCollection;

/**
 * @extends AbstractCollection<Order>
 */
final class OrderCollection extends AbstractCollection
{
    protected function elementClass(): string
    {
        return Order::class;
    }
}
