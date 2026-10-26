<?php

declare(strict_types=1);

namespace App\Notifications\Application\Query\GetNotifications;

final readonly class GetNotificationsQueryResult
{
    /**
     * @param list<NotificationItemQueryResult> $items
     */
    public function __construct(
        public array $items,
        public int $totalCount,
        public int $page,
        public int $limit,
    ) {
    }
}
