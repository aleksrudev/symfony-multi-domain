<?php

declare(strict_types=1);

namespace App\Catalog\Application\Query\GetProducts;

use App\Catalog\Domain\Entity\Product;
use App\Catalog\Domain\Repository\ProductRepositoryInterface;

final readonly class GetProductsHandler
{
    public function __construct(
        private ProductRepositoryInterface $repository,
    ) {
    }

    /**
     * @return array{items: list<Product>, total: int, page: int, limit: int}
     */
    public function __invoke(GetProductsQuery $query): array
    {
        $page = max(1, $query->page);
        $limit = min(50, max(1, $query->limit));

        $result = $this->repository->findPublishedPaginated($page, $limit);

        return [
            'items' => $result['items'],
            'total' => $result['total'],
            'page' => $page,
            'limit' => $limit,
        ];
    }
}

