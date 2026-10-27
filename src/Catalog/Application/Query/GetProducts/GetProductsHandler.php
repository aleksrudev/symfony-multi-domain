<?php

declare(strict_types=1);

namespace App\Catalog\Application\Query\GetProducts;

use App\Catalog\Application\Query\GetProduct\Result\GetProductQueryResult;
use App\Catalog\Application\Query\GetProducts\Result\GetProductsQueryResult;
use App\Catalog\Domain\DataProvider\ProductDataProviderInterface;
use App\Catalog\Domain\Entity\Product;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler(bus: 'query.bus')]
final readonly class GetProductsHandler
{
    public function __construct(
        private ProductDataProviderInterface $dataProvider,
    ) {
    }

    public function __invoke(GetProductsQuery $query): GetProductsQueryResult
    {
        $page = max(1, $query->page);
        $limit = min(50, max(1, $query->limit));
        $offset = ($page - 1) * $limit;

        $paginated = $this->dataProvider->findPublishedPaginated($offset, $limit);

        $items = array_map(
            static fn (Product $product): GetProductQueryResult => new GetProductQueryResult(
                productId: $product->id()->value,
                name: $product->name(),
                priceAmount: $product->priceAmount(),
                currency: $product->currency()->value,
                published: $product->isPublished(),
            ),
            $paginated->items->all(),
        );

        return new GetProductsQueryResult(
            items: $items,
            total: $paginated->count,
            page: $page,
            limit: $limit,
        );
    }
}
