<?php

declare(strict_types=1);

namespace App\Notifications\Presentation\Contracts;

use App\Notifications\Presentation\Contracts\Dto\EnqueueNotificationInput;
use App\Notifications\Presentation\Contracts\Dto\NotificationStatusDto;

interface NotificationsContractInterface
{
    public function enqueueNotification(EnqueueNotificationInput $input): void;

    public function getNotificationStatus(string $orderId, string $type): ?NotificationStatusDto;

    /**
     * @return list<NotificationStatusDto>
     */
    public function getNotificationsForOrder(string $orderId): array;
}
