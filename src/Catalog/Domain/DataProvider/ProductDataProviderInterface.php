<?php

declare(strict_types=1);

namespace App\Catalog\Domain\DataProvider;

use App\Catalog\Domain\Collection\ProductPaginatedCollection;
use App\Catalog\Domain\Entity\Product;
use App\Catalog\Domain\Exception\ProductNotFoundException;
use App\Catalog\Domain\ValueObject\ProductId;

interface ProductDataProviderInterface
{
    /**
     * @throws ProductNotFoundException
     */
    public function findById(ProductId $id): Product;

    /**
     * @throws ProductNotFoundException
     */
    public function findPublishedById(ProductId $id): Product;

    public function findPublishedPaginated(int $offset, int $limit): ProductPaginatedCollection;
}

