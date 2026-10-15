<?php

declare(strict_types=1);

namespace App\Catalog\Presentation\Http\Controller\Api\Admin;

use App\Catalog\Application\Command\UpdateProduct\UpdateProductCommand;
use App\Catalog\Application\Query\GetProduct\GetProductQuery;
use App\Catalog\Application\Query\GetProduct\GetProductQueryResult;
use App\Shared\Application\Bus\Command\CommandBusInterface;
use App\Shared\Application\Bus\Query\QueryBusInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final readonly class UpdateProductController
{
    public function __construct(
        private CommandBusInterface $commandBus,
        private QueryBusInterface $queryBus,
    ) {
    }

    #[Route('/admin/products/{id}', name: 'admin_products_update', methods: ['PATCH'])]
    public function __invoke(string $id, Request $request): JsonResponse
    {
        /** @var array<string, mixed>|null $data */
        $data = json_decode((string) $request->getContent(), true);
        if (!is_array($data)) {
            return new JsonResponse(['text' => 'Invalid JSON payload.', 'error' => 'invalid_payload'], Response::HTTP_BAD_REQUEST);
        }

        $price = isset($data['price']) ? (int) $data['price'] : null;
        $published = isset($data['published']) ? (bool) $data['published'] : null;

        $this->commandBus->dispatch(new UpdateProductCommand(
            productId: $id,
            priceAmount: $price,
            published: $published,
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

