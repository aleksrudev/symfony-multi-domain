<?php

declare(strict_types=1);

namespace App\Catalog\Presentation\Http\Controller\Api\Visitor;

use App\Catalog\Application\Query\GetProduct\GetProductQuery;
use App\Catalog\Application\Query\GetProduct\GetProductQueryResult;
use App\Shared\Application\Bus\Query\QueryBusInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

final readonly class GetProductController
{
    public function __construct(
        private QueryBusInterface $queryBus,
    ) {
    }

    #[Route('/products/{id}', name: 'products_item', methods: ['GET'])]
    public function __invoke(string $id): JsonResponse
    {
        /** @var GetProductQueryResult $product */
        $product = $this->queryBus->dispatch(new GetProductQuery($id));

        return new JsonResponse([
            'id' => $product->productId,
            'name' => $product->name,
            'price' => $product->priceAmount,
            'currency' => $product->currency,
            'published' => $product->published,
        ]);
    }
}

