<?php

declare(strict_types=1);

namespace App\Notifications\Domain\Action;

use App\Notifications\Domain\DataProvider\NotificationDataProviderInterface;
use App\Notifications\Domain\Entity\Notification;
use App\Notifications\Domain\Enum\NotificationType;
use App\Notifications\Domain\Repository\NotificationRepositoryInterface;
use App\Notifications\Domain\ValueObject\NotificationId;

final readonly class CreateNotificationAction
{
    public function __construct(
        private NotificationDataProviderInterface $dataProvider,
        private NotificationRepositoryInterface $repository,
    ) {
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function __invoke(
        string $orderId,
        NotificationType $type,
        string $buyerId,
        array $payload,
    ): Notification {
        $existing = $this->dataProvider->findByOrderIdAndType($orderId, $type);
        if (null !== $existing) {
            return $existing;
        }

        $notification = Notification::createPending(
            id: new NotificationId(),
            orderId: $orderId,
            type: $type,
            buyerId: $buyerId,
            payload: $payload,
        );

        $this->repository->save($notification);

        return $notification;
    }
}
