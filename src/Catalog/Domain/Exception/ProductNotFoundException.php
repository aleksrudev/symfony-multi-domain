<?php

declare(strict_types=1);

namespace App\Catalog\Domain\Exception;

use App\Shared\Domain\Exception\DomainException;

final class ProductNotFoundException extends DomainException
{
    public function __construct(string $productId)
    {
        parent::__construct(sprintf('Product "%s" not found.', $productId));
    }
}

