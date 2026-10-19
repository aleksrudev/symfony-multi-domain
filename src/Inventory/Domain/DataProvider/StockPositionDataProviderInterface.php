<?php

declare(strict_types=1);

namespace App\Inventory\Domain\DataProvider;

use App\Inventory\Domain\Collection\StockPositionCollection;
use App\Inventory\Domain\Entity\StockPosition;
use App\Inventory\Domain\Exception\StockPositionNotFoundException;
use App\Inventory\Domain\ValueObject\ProductId;

interface StockPositionDataProviderInterface
{
    /**
     * @throws StockPositionNotFoundException
     */
    public function findByProductId(ProductId $productId, bool $lock = false): StockPosition;

    public function findAll(): StockPositionCollection;
}

