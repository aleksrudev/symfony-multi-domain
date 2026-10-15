<?php

declare(strict_types=1);

namespace App\Catalog\Presentation\Http\Controller\Api;

use App\Catalog\Application\Query\GetProduct\GetProductQuery;
use App\Catalog\Application\Query\GetProducts\GetProductsQuery;
use App\Catalog\Domain\Entity\Product;
use App\Shared\Application\Bus\Query\QueryBusInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

final readonly class ProductController
{
    public function __construct(
        private QueryBusInterface $queryBus,
    ) {
    }

    #[Route('/products', name: 'products_list', methods: ['GET'])]
    public function list(Request $request): JsonResponse
    {
        $page = max(1, $request->query->getInt('page', 1));
        $limit = min(50, max(1, $request->query->getInt('limit', 10)));

        /** @var array{items: list<Product>, total: int, page: int, limit: int} $result */
        $result = $this->queryBus->dispatch(new GetProductsQuery($page, $limit));

        $items = array_map(
            static fn (Product $product): array => [
                'id' => $product->id(),
                'name' => $product->name(),
                'price' => $product->priceAmount(),
                'currency' => $product->currency(),
                'published' => $product->isPublished(),
            ],
            $result['items'],
        );

        return new JsonResponse([
            'items' => $items,
            'total' => $result['total'],
            'page' => $result['page'],
            'limit' => $result['limit'],
        ]);
    }

    #[Route('/products/{id}', name: 'products_item', methods: ['GET'])]
    public function item(string $id): JsonResponse
    {
        /** @var Product $product */
        $product = $this->queryBus->dispatch(new GetProductQuery($id));

        return new JsonResponse([
            'id' => $product->id(),
            'name' => $product->name(),
            'price' => $product->priceAmount(),
            'currency' => $product->currency(),
            'published' => $product->isPublished(),
        ]);
    }
}

