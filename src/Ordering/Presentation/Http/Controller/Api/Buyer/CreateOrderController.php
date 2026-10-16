<?php

declare(strict_types=1);

namespace App\Ordering\Presentation\Http\Controller\Api\Buyer;

use App\Ordering\Application\Command\CreateDraft\CreateDraftCommand;
use App\Ordering\Application\Query\GetDraft\GetDraftQuery;
use App\Ordering\Application\Query\GetOrder\GetOrderQueryResult;
use App\Shared\Application\Bus\Command\CommandBusInterface;
use App\Shared\Application\Bus\Query\QueryBusInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

final readonly class CreateOrderController
{
    public function __construct(
        private CommandBusInterface $commandBus,
        private QueryBusInterface $queryBus,
    ) {
    }

    #[Route('/orders', name: 'orders_create', methods: ['POST'], format: 'json')]
    public function __invoke(
        Request $httpRequest,
        #[CurrentUser]
        ?UserInterface $user,
    ): JsonResponse {
        if (null === $user) {
            return new JsonResponse(['text' => 'Unauthorized.', 'error' => 'unauthorized'], Response::HTTP_UNAUTHORIZED);
        }

        $idempotencyKey = $httpRequest->headers->get('Idempotency-Key');
        $requestHash = sha1((string) $httpRequest->getContent());

        $this->commandBus->dispatch(new CreateDraftCommand(
            buyerId: $user->getUserIdentifier(),
            idempotencyKey: $idempotencyKey,
            requestHash: $requestHash,
        ));

        /** @var GetOrderQueryResult $result */
        $result = $this->queryBus->dispatch(new GetDraftQuery(
            buyerId: $user->getUserIdentifier(),
        ));

        return new JsonResponse([
            'id' => $result->id,
            'buyerId' => $result->buyerId,
            'status' => $result->status,
            'items' => $result->items,
            'createdAt' => $result->createdAt,
            'updatedAt' => $result->updatedAt,
        ], Response::HTTP_OK);
    }
}
