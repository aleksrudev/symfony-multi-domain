<?php

declare(strict_types=1);

namespace App\Ordering\Domain\Exception;

use App\Ordering\Domain\ValueObject\ProductId;
use App\Shared\Domain\Exception\DomainException;

final class ProductNotAvailableException extends DomainException
{
    public function __construct(string|ProductId $productId)
    {
        $id = $productId instanceof ProductId ? $productId->value : $productId;
        parent::__construct(sprintf('Product "%s" is not available for purchase.', $id));
    }
}

