<?php

declare(strict_types=1);

namespace App\Inventory\Domain\Collection;

use App\Inventory\Domain\Entity\StockPosition;
use App\Shared\Domain\Collection\AbstractCollection;

/**
 * @extends AbstractCollection<StockPosition>
 */
final class StockPositionCollection extends AbstractCollection
{
    protected function elementClass(): string
    {
        return StockPosition::class;
    }
}
