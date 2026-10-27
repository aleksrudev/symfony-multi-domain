<?php

declare(strict_types=1);

namespace App\Ordering\Presentation\Http\Controller\Api\Admin;

use App\Ordering\Application\Query\GetOrderDiagnostics\GetOrderDiagnosticsQuery;
use App\Ordering\Application\Query\GetOrderDiagnostics\Result\GetOrderDiagnosticsQueryResult;
use App\Shared\Application\Bus\Query\QueryBusInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_ADMIN')]
final readonly class GetOrderDiagnosticsController
{
    public function __construct(
        private QueryBusInterface $queryBus,
    ) {
    }

    #[Route('/admin/diagnostics/orders/{id}', name: 'admin_diagnostics_orders_item', methods: ['GET'], format: 'json')]
    public function __invoke(string $id): JsonResponse
    {
        /** @var GetOrderDiagnosticsQueryResult $result */
        $result = $this->queryBus->dispatch(new GetOrderDiagnosticsQuery(
            orderId: $id,
        ));

        return new JsonResponse($result->toArray(), Response::HTTP_OK);
    }
}
