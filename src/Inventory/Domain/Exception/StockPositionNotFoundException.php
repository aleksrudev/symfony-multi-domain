<?php

declare(strict_types=1);

namespace App\Inventory\Domain\Exception;

use App\Shared\Domain\Exception\DomainException;

final class StockPositionNotFoundException extends DomainException
{
    public function __construct(string $productId)
    {
        parent::__construct(sprintf('Stock position for product "%s" not found.', $productId));
    }
}

