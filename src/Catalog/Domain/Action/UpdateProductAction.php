<?php

declare(strict_types=1);

namespace App\Catalog\Domain\Action;

use App\Catalog\Domain\DataProvider\ProductDataProviderInterface;
use App\Catalog\Domain\Entity\Product;
use App\Catalog\Domain\Repository\ProductRepositoryInterface;
use App\Catalog\Domain\ValueObject\ProductId;

final readonly class UpdateProductAction
{
    public function __construct(
        private ProductDataProviderInterface $dataProvider,
        private ProductRepositoryInterface $repository,
    ) {
    }

    public function __invoke(ProductId $id, ?int $priceAmount, ?bool $published): Product
    {
        $product = $this->dataProvider->findById($id);

        if (null !== $priceAmount) {
            $product->changePrice($priceAmount);
        }

        if (null !== $published) {
            $published ? $product->publish() : $product->unpublish();
        }

        $this->repository->save($product);

        return $product;
    }
}
