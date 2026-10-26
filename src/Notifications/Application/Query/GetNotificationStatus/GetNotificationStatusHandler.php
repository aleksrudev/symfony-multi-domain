<?php

declare(strict_types=1);

namespace App\Notifications\Application\Query\GetNotificationStatus;

use App\Notifications\Domain\DataProvider\NotificationDataProviderInterface;
use App\Notifications\Domain\Enum\NotificationType;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler(bus: 'query.bus')]
final readonly class GetNotificationStatusHandler
{
    public function __construct(
        private NotificationDataProviderInterface $dataProvider,
    ) {
    }

    public function __invoke(GetNotificationStatusQuery $query): ?NotificationStatusQueryResult
    {
        try {
            $type = NotificationType::from($query->type);
        } catch (\ValueError) {
            return null;
        }

        $notification = $this->dataProvider->findByOrderIdAndType($query->orderId, $type);
        if (null === $notification) {
            return null;
        }

        return new NotificationStatusQueryResult(
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
}
