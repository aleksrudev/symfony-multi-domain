<?php

declare(strict_types=1);

namespace App\Catalog\Presentation\Http\Error;

use App\Catalog\Domain\Exception\InvalidProductPriceException;
use App\Catalog\Domain\Exception\ProductNotFoundException;
use App\Shared\Presentation\Http\Error\DomainErrorProviderInterface;

final class CatalogErrorProvider implements DomainErrorProviderInterface
{
    public static function getErrors(): array
    {
        return [
            ProductNotFoundException::class => [
                'error' => 'product_not_found',
                'status' => 404,
            ],
            InvalidProductPriceException::class => [
                'error' => 'invalid_product_price',
                'status' => 422,
            ],
        ];
    }
}
