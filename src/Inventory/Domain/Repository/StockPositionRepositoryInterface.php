<?php

declare(strict_types=1);

namespace App\Inventory\Domain\Repository;

use App\Inventory\Domain\Entity\StockPosition;

interface StockPositionRepositoryInterface
{
    public function save(StockPosition $position): void;

    public function remove(StockPosition $position): void;
}

