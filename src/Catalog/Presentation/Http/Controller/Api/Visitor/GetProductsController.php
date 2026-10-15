<?php

declare(strict_types=1);

namespace App\Catalog\Presentation\Http\Controller\Api\Visitor;

use App\Catalog\Application\Query\GetProduct\GetProductQueryResult;
use App\Catalog\Application\Query\GetProducts\GetProductsQuery;
use App\Catalog\Application\Query\GetProducts\GetProductsQueryResult;
use App\Shared\Application\Bus\Query\QueryBusInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

final readonly class GetProductsController
{
    public function __construct(
        private QueryBusInterface $queryBus,
    ) {
    }

    #[Route('/products', name: 'products_list', methods: ['GET'])]
    public function __invoke(Request $request): JsonResponse
    {
        $page = max(1, $request->query->getInt('page', 1));
        $limit = min(50, max(1, $request->query->getInt('limit', 10)));

        /** @var GetProductsQueryResult $result */
        $result = $this->queryBus->dispatch(new GetProductsQuery($page, $limit));

        $items = array_map(
            static fn (GetProductQueryResult $item): array => [
                'id' => $item->productId,
                'name' => $item->name,
                'price' => $item->priceAmount,
                'currency' => $item->currency,
                'published' => $item->published,
            ],
            $result->items,
        );

        return new JsonResponse([
            'items' => $items,
            'total' => $result->total,
            'page' => $result->page,
            'limit' => $result->limit,
        ]);
    }
}

