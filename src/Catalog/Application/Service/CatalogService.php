<?php

declare(strict_types=1);

namespace App\Catalog\Application\Service;

use App\Catalog\Contracts\CatalogContractInterface;
use App\Catalog\Contracts\Offer;
use App\Catalog\Domain\Repository\ProductRepositoryInterface;

final readonly class CatalogService implements CatalogContractInterface
{
    public function __construct(
        private ProductRepositoryInterface $repository,
    ) {
    }

    public function getOffer(string $productId): ?Offer
    {
        $product = $this->repository->findPublishedById($productId);
        if (null === $product) {
            return null;
        }

        return new Offer(
            productId: $product->id(),
            name: $product->name(),
            priceAmount: $product->priceAmount(),
            currency: $product->currency(),
        );
    }
}

