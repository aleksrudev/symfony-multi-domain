<?php

declare(strict_types=1);

namespace App\Inventory\Domain\Exception;

use App\Shared\Domain\Exception\DomainException;

final class InsufficientStockException extends DomainException
{
    public function __construct(string $productId, int $requested, int $available)
    {
        parent::__construct(sprintf(
            'Insufficient stock for product "%s": requested %d, available %d.',
            $productId,
            $requested,
            $available,
        ));
    }
}
