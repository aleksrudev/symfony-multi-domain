<?php

declare(strict_types=1);

namespace App\Inventory\Presentation\Http\Controller\Api\Admin;

use App\Inventory\Application\Command\ChangeStockOnHand\ChangeStockOnHandCommand;
use App\Inventory\Application\Query\GetStockPosition\GetStockPositionQuery;
use App\Inventory\Application\Query\GetStockPosition\GetStockPositionQueryResult;
use App\Inventory\Presentation\Http\Request\UpdateStockOnHandRequest;
use App\Shared\Application\Bus\Command\CommandBusInterface;
use App\Shared\Application\Bus\Query\QueryBusInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;

final readonly class UpdateStockOnHandController
{
    public function __construct(
        private CommandBusInterface $commandBus,
        private QueryBusInterface $queryBus,
    ) {
    }

    #[Route('/admin/stock/{productId}', name: 'admin_stock_update', methods: ['PATCH'], format: 'json')]
    public function __invoke(
        string $productId,
        #[MapRequestPayload(acceptFormat: 'json')]
        UpdateStockOnHandRequest $request,
    ): JsonResponse {
        \assert(null !== $request->onHand);

        $this->commandBus->dispatch(new ChangeStockOnHandCommand(
            productId: $productId,
            onHand: $request->onHand,
        ));

        /** @var GetStockPositionQueryResult $result */
        $result = $this->queryBus->dispatch(new GetStockPositionQuery($productId));

        return new JsonResponse([
            'productId' => $result->productId,
            'onHand' => $result->onHand,
            'reserved' => $result->reserved,
            'available' => $result->available,
            'updatedAt' => $result->updatedAt,
        ]);
    }
}

