<?php

declare(strict_types=1);

namespace App\Catalog\Presentation\Http\Controller\Api\Visitor;

use App\Catalog\Application\Query\GetProduct\GetProductQueryResult;
use App\Catalog\Application\Query\GetProducts\GetProductsQuery;
use App\Catalog\Application\Query\GetProducts\GetProductsQueryResult;
use App\Catalog\Presentation\Http\Request\GetProductsRequest;
use App\Shared\Application\Bus\Query\QueryBusInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapQueryString;
use Symfony\Component\Routing\Attribute\Route;

final readonly class GetProductsController
{
    public function __construct(
        private QueryBusInterface $queryBus,
    ) {
    }

    #[Route('/products', name: 'products_list', methods: ['GET'], format: 'json')]
    public function __invoke(
        #[MapQueryString(validationFailedStatusCode: Response::HTTP_UNPROCESSABLE_ENTITY)]
        GetProductsRequest $request = new GetProductsRequest(),
    ): JsonResponse {
        /** @var GetProductsQueryResult $result */
        $result = $this->queryBus->dispatch(new GetProductsQuery($request->page, $request->limit));

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
