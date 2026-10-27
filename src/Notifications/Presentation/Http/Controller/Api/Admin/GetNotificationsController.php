<?php

declare(strict_types=1);

namespace App\Notifications\Presentation\Http\Controller\Api\Admin;

use App\Notifications\Application\Query\GetNotifications\GetNotificationsQuery;
use App\Notifications\Application\Query\GetNotifications\Result\GetNotificationsQueryResult;
use App\Notifications\Application\Query\GetNotifications\Result\NotificationItemQueryResult;
use App\Notifications\Presentation\Http\Request\GetNotificationsRequest;
use App\Shared\Application\Bus\Query\QueryBusInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapQueryString;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_ADMIN')]
final readonly class GetNotificationsController
{
    public function __construct(
        private QueryBusInterface $queryBus,
    ) {
    }

    #[Route('/admin/notifications', name: 'admin_notifications_list', methods: ['GET'], format: 'json')]
    public function __invoke(
        #[MapQueryString(validationFailedStatusCode: 422)]
        GetNotificationsRequest $request = new GetNotificationsRequest(),
    ): JsonResponse {
        /** @var GetNotificationsQueryResult $result */
        $result = $this->queryBus->dispatch(new GetNotificationsQuery(
            page: $request->page,
            limit: $request->limit,
        ));

        return new JsonResponse([
            'items' => array_map(static fn (NotificationItemQueryResult $item): array => $item->toArray(), $result->items),
            'page' => $result->page,
            'limit' => $result->limit,
            'total' => $result->totalCount,
        ], Response::HTTP_OK);
    }
}
