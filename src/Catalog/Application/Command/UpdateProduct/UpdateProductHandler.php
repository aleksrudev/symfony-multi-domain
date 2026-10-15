<?php

declare(strict_types=1);

namespace App\Catalog\Application\Command\UpdateProduct;

use App\Catalog\Domain\Exception\ProductNotFoundException;
use App\Catalog\Domain\Repository\ProductRepositoryInterface;

final readonly class UpdateProductHandler
{
    public function __construct(
        private ProductRepositoryInterface $repository,
    ) {
    }

    public function __invoke(UpdateProductCommand $command): void
    {
        $product = $this->repository->findById($command->productId);
        if (null === $product) {
            throw new ProductNotFoundException($command->productId);
        }

        if (null !== $command->priceAmount) {
            $product->changePrice($command->priceAmount);
        }

        if (null !== $command->published) {
            $command->published ? $product->publish() : $product->unpublish();
        }

        $this->repository->save($product);
    }
}

