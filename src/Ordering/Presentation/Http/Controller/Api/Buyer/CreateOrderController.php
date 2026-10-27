<?php

declare(strict_types=1);

namespace App\Ordering\Presentation\Http\Controller\Api\Buyer;

use App\Ordering\Application\Command\CreateDraft\CreateDraftCommand;
use App\Ordering\Application\Query\GetDraft\GetDraftQuery;
use App\Ordering\Application\Query\GetDraft\Result\GetDraftQueryResult;
use App\Ordering\Application\Query\GetOrder\Result\OrderItemQueryResult;
use App\Shared\Application\Bus\Command\CommandBusInterface;
use App\Shared\Application\Bus\Query\QueryBusInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_BUYER')]
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
        UserInterface $user,
    ): JsonResponse {
        $idempotencyKey = $httpRequest->headers->get('Idempotency-Key');
        $requestHash = sha1((string) $httpRequest->getContent());

        $this->commandBus->dispatch(new CreateDraftCommand(
            buyerId: $user->getUserIdentifier(),
            idempotencyKey: $idempotencyKey,
            requestHash: $requestHash,
        ));

        /** @var GetDraftQueryResult $result */
        $result = $this->queryBus->dispatch(new GetDraftQuery(
            buyerId: $user->getUserIdentifier(),
        ));

        return new JsonResponse([
            'id' => $result->id,
            'buyerId' => $result->buyerId,
            'status' => $result->status,
            'items' => array_map(static fn (OrderItemQueryResult $item): array => $item->toArray(), $result->items),
            'totalAmount' => $result->totalAmount,
            'currency' => $result->currency,
            'createdAt' => $result->createdAt,
            'updatedAt' => $result->updatedAt,
        ], Response::HTTP_OK);
    }
}
