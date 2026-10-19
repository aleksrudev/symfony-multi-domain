<?php

declare(strict_types=1);

namespace App\Ordering\Presentation\Http\Controller\Api\Buyer;

use App\Ordering\Application\Query\GetOrder\GetOrderQueryResult;
use App\Ordering\Application\Query\GetOrders\GetOrdersQuery;
use App\Ordering\Application\Query\GetOrders\GetOrdersQueryResult;
use App\Ordering\Presentation\Http\Request\GetOrdersRequest;
use App\Shared\Application\Bus\Query\QueryBusInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapQueryString;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

final readonly class GetOrdersController
{
    public function __construct(
        private QueryBusInterface $queryBus,
    ) {
    }

    #[Route('/orders', name: 'orders_list', methods: ['GET'], format: 'json')]
    public function __invoke(
        #[CurrentUser]
        ?UserInterface $user,
        #[MapQueryString(validationFailedStatusCode: Response::HTTP_UNPROCESSABLE_ENTITY)]
        GetOrdersRequest $request = new GetOrdersRequest(),
    ): JsonResponse {
        if (null === $user) {
            return new JsonResponse(['text' => 'Unauthorized.', 'error' => 'unauthorized'], Response::HTTP_UNAUTHORIZED);
        }

        /** @var GetOrdersQueryResult $result */
        $result = $this->queryBus->dispatch(new GetOrdersQuery(
            buyerId: $user->getUserIdentifier(),
            page: $request->page,
            limit: $request->limit,
        ));

        $items = array_map(
            static fn (GetOrderQueryResult $item): array => [
                'id' => $item->id,
                'buyerId' => $item->buyerId,
                'status' => $item->status,
                'items' => $item->items,
                'totalAmount' => $item->totalAmount,
                'currency' => $item->currency,
                'createdAt' => $item->createdAt,
                'updatedAt' => $item->updatedAt,
            ],
            $result->items,
        );

        return new JsonResponse([
            'items' => $items,
            'total' => $result->total,
            'page' => $result->page,
            'limit' => $result->limit,
        ], Response::HTTP_OK);
    }
}
