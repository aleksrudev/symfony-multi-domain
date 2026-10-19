<?php

declare(strict_types=1);

namespace App\Ordering\Presentation\Http\Controller\Api\Buyer;

use App\Ordering\Application\Command\ReplaceOrderItems\ReplaceOrderItemsCommand;
use App\Ordering\Application\Query\GetOrder\GetOrderQuery;
use App\Ordering\Application\Query\GetOrder\GetOrderQueryResult;
use App\Ordering\Presentation\Http\Request\OrderItemInput;
use App\Ordering\Presentation\Http\Request\ReplaceOrderItemsRequest;
use App\Shared\Application\Bus\Command\CommandBusInterface;
use App\Shared\Application\Bus\Query\QueryBusInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

final readonly class ReplaceOrderItemsController
{
    public function __construct(
        private CommandBusInterface $commandBus,
        private QueryBusInterface $queryBus,
    ) {
    }

    #[Route('/orders/{id}/items', name: 'orders_replace_items', methods: ['PUT'], format: 'json')]
    public function __invoke(
        string $id,
        #[MapRequestPayload(acceptFormat: 'json')]
        ReplaceOrderItemsRequest $request,
        #[CurrentUser]
        ?UserInterface $user,
    ): JsonResponse {
        if (null === $user) {
            return new JsonResponse(['text' => 'Unauthorized.', 'error' => 'unauthorized'], Response::HTTP_UNAUTHORIZED);
        }

        $items = array_map(
            static fn (OrderItemInput $input): array => [
                'productId' => $input->productId,
                'quantity' => $input->quantity,
            ],
            $request->items,
        );

        $this->commandBus->dispatch(new ReplaceOrderItemsCommand(
            orderId: $id,
            buyerId: $user->getUserIdentifier(),
            items: $items,
        ));

        /** @var GetOrderQueryResult $result */
        $result = $this->queryBus->dispatch(new GetOrderQuery(
            orderId: $id,
            buyerId: $user->getUserIdentifier(),
        ));

        return new JsonResponse([
            'id' => $result->id,
            'buyerId' => $result->buyerId,
            'status' => $result->status,
            'items' => $result->items,
            'totalAmount' => $result->totalAmount,
            'currency' => $result->currency,
            'createdAt' => $result->createdAt,
            'updatedAt' => $result->updatedAt,
        ], Response::HTTP_OK);
    }
}
