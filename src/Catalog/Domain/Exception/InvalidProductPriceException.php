<?php

declare(strict_types=1);

namespace App\Catalog\Domain\Exception;

use App\Shared\Domain\Exception\DomainException;

final class InvalidProductPriceException extends DomainException
{
    public function __construct(int $priceAmount)
    {
        parent::__construct(sprintf('Product price must be greater than zero, got %d.', $priceAmount));
    }
}
