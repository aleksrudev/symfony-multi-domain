<?php

declare(strict_types=1);

namespace App\Catalog\Application\Query\GetProduct;

use App\Catalog\Domain\Entity\Product;
use App\Catalog\Domain\Exception\ProductNotFoundException;
use App\Catalog\Domain\Repository\ProductRepositoryInterface;

final readonly class GetProductHandler
{
    public function __construct(
        private ProductRepositoryInterface $repository,
    ) {
    }

    public function __invoke(GetProductQuery $query): Product
    {
        $product = $query->onlyPublished
            ? $this->repository->findPublishedById($query->productId)
            : $this->repository->findById($query->productId);

        if (null === $product) {
            throw new ProductNotFoundException($query->productId);
        }

        return $product;
    }
}

