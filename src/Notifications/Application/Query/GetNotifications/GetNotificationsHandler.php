<?php

declare(strict_types=1);

namespace App\Notifications\Application\Query\GetNotifications;

use App\Notifications\Application\Query\GetNotifications\Result\GetNotificationsQueryResult;
use App\Notifications\Application\Query\GetNotifications\Result\NotificationItemQueryResult;
use App\Notifications\Domain\DataProvider\NotificationDataProviderInterface;
use App\Notifications\Domain\Entity\Notification;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler(bus: 'query.bus')]
final readonly class GetNotificationsHandler
{
    public function __construct(
        private NotificationDataProviderInterface $dataProvider,
    ) {
    }

    public function __invoke(GetNotificationsQuery $query): GetNotificationsQueryResult
    {
        $notifications = $this->dataProvider->findAllPaginated($query->page, $query->limit);
        $totalCount = $this->dataProvider->countAll();

        $items = [];
        foreach ($notifications as $notification) {
            /* @var Notification $notification */
            $items[] = new NotificationItemQueryResult(
                id: $notification->id()->value,
                orderId: $notification->orderId(),
                type: $notification->type()->value,
                buyerId: $notification->buyerId(),
                status: $notification->status()->value,
                attempts: $notification->attempts(),
                payload: $notification->payload(),
                errorMessage: $notification->errorMessage(),
                createdAt: $notification->createdAt()->format(\DateTimeInterface::ATOM),
                updatedAt: $notification->updatedAt()->format(\DateTimeInterface::ATOM),
                sentAt: $notification->sentAt()?->format(\DateTimeInterface::ATOM),
            );
        }

        return new GetNotificationsQueryResult(
            items: $items,
            totalCount: $totalCount,
            page: $query->page,
            limit: $query->limit,
        );
    }
}
