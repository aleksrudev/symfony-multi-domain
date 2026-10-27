<?php

declare(strict_types=1);

namespace App\Ordering\Infrastructure\Integration\Notifications;

use App\Notifications\Presentation\Contracts\Dto\NotificationStatusDto;
use App\Notifications\Presentation\Contracts\NotificationsContractInterface;
use App\Ordering\Application\Query\GetOrderDiagnostics\Provider\OrderNotificationStatus;
use App\Ordering\Application\Query\GetOrderDiagnostics\Provider\OrderNotificationStatusProviderInterface;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

#[AsAlias(OrderNotificationStatusProviderInterface::class)]
final readonly class NotificationsStatusProvider implements OrderNotificationStatusProviderInterface
{
    public function __construct(
        private NotificationsContractInterface $notificationsContract,
    ) {
    }

    public function getNotificationsForOrder(string $orderId): array
    {
        $notifications = $this->notificationsContract->getNotificationsForOrder($orderId);

        return array_map(
            static fn (NotificationStatusDto $dto): OrderNotificationStatus => new OrderNotificationStatus(
                id: $dto->id,
                type: $dto->type,
                status: $dto->status,
                attempts: $dto->attempts,
                errorMessage: $dto->errorMessage,
                createdAt: $dto->createdAt,
                updatedAt: $dto->updatedAt,
                sentAt: $dto->sentAt,
            ),
            $notifications,
        );
    }
}
