<?php

declare(strict_types=1);

namespace App\Catalog\Presentation\Http\Controller\Api\Admin;

use App\Catalog\Application\Command\UpdateProduct\UpdateProductCommand;
use App\Catalog\Application\Query\GetProduct\GetProductQuery;
use App\Catalog\Application\Query\GetProduct\GetProductQueryResult;
use App\Catalog\Presentation\Http\Request\UpdateProductRequest;
use App\Shared\Application\Bus\Command\CommandBusInterface;
use App\Shared\Application\Bus\Query\QueryBusInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;

final readonly class UpdateProductController
{
    public function __construct(
        private CommandBusInterface $commandBus,
        private QueryBusInterface $queryBus,
    ) {
    }

    #[Route('/admin/products/{id}', name: 'admin_products_update', methods: ['PATCH'], format: 'json')]
    public function __invoke(
        string $id,
        #[MapRequestPayload(acceptFormat: 'json')]
        UpdateProductRequest $request,
    ): JsonResponse {
        $this->commandBus->dispatch(new UpdateProductCommand(
            productId: $id,
            priceAmount: $request->price,
            published: $request->published,
        ));

        /** @var GetProductQueryResult $product */
        $product = $this->queryBus->dispatch(new GetProductQuery($id, onlyPublished: false));

        return new JsonResponse([
            'id' => $product->productId,
            'name' => $product->name,
            'price' => $product->priceAmount,
            'currency' => $product->currency,
            'published' => $product->published,
        ]);
    }
}
