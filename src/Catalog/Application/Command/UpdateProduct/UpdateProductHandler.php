<?php

declare(strict_types=1);

namespace App\Catalog\Application\Command\UpdateProduct;

use App\Catalog\Domain\Action\UpdateProductAction;
use App\Catalog\Domain\ValueObject\ProductId;

final readonly class UpdateProductHandler
{
    public function __construct(
        private UpdateProductAction $updateProductAction,
    ) {
    }

    public function __invoke(UpdateProductCommand $command): void
    {
        ($this->updateProductAction)(
            id: new ProductId($command->productId),
            priceAmount: $command->priceAmount,
            published: $command->published,
        );
    }
}
