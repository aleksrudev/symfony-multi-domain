<?php

declare(strict_types=1);

namespace App\Catalog\Application\Query\GetProduct;

use App\Catalog\Application\Query\GetProduct\Result\GetProductQueryResult;
use App\Catalog\Domain\DataProvider\ProductDataProviderInterface;
use App\Catalog\Domain\ValueObject\ProductId;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler(bus: 'query.bus')]
final readonly class GetProductHandler
{
    public function __construct(
        private ProductDataProviderInterface $dataProvider,
    ) {
    }

    public function __invoke(GetProductQuery $query): GetProductQueryResult
    {
        $id = new ProductId($query->productId);

        $product = $query->onlyPublished
            ? $this->dataProvider->findPublishedById($id)
            : $this->dataProvider->findById($id);

        return new GetProductQueryResult(
            productId: $product->id()->value,
            name: $product->name(),
            priceAmount: $product->priceAmount(),
            currency: $product->currency()->value,
            published: $product->isPublished(),
        );
    }
}
