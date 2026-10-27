<?php

declare(strict_types=1);

namespace App\Catalog\Domain\Exception;

use App\Catalog\Domain\ValueObject\ProductId;
use App\Shared\Domain\Exception\DomainException;

final class ProductNotFoundException extends DomainException
{
    public function __construct(string|ProductId $productId)
    {
        $id = $productId instanceof ProductId ? $productId->value : $productId;
        parent::__construct(sprintf('Product "%s" not found.', $id));
    }
}
