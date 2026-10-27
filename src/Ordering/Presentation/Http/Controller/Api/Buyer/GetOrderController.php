<?php

declare(strict_types=1);

namespace App\Ordering\Presentation\Http\Controller\Api\Buyer;

use App\Ordering\Application\Query\GetOrder\GetOrderQuery;
use App\Ordering\Application\Query\GetOrder\Result\GetOrderQueryResult;
use App\Ordering\Application\Query\GetOrder\Result\OrderItemQueryResult;
use App\Shared\Application\Bus\Query\QueryBusInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_BUYER')]
final readonly class GetOrderController
{
    public function __construct(
        private QueryBusInterface $queryBus,
    ) {
    }

    #[Route('/orders/{id}', name: 'orders_item', methods: ['GET'], format: 'json')]
    public function __invoke(
        string $id,
        #[CurrentUser]
        UserInterface $user,
    ): JsonResponse {
        /** @var GetOrderQueryResult $result */
        $result = $this->queryBus->dispatch(new GetOrderQuery(
            orderId: $id,
            buyerId: $user->getUserIdentifier(),
        ));

        return new JsonResponse([
            'id' => $result->id,
            'buyerId' => $result->buyerId,
            'status' => $result->status,
            'items' => array_map(static fn (OrderItemQueryResult $item): array => $item->toArray(), $result->items),
            'totalAmount' => $result->totalAmount,
            'currency' => $result->currency,
            'rejectionReason' => $result->rejectionReason,
            'createdAt' => $result->createdAt,
            'updatedAt' => $result->updatedAt,
        ], Response::HTTP_OK);
    }
}
