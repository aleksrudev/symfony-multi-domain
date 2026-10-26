<?php

declare(strict_types=1);

namespace App\Notifications\Application\Query\GetNotifications;

use App\Shared\Application\Bus\Query\QueryInterface;

final readonly class GetNotificationsQuery implements QueryInterface
{
    public function __construct(
        public int $page = 1,
        public int $limit = 10,
    ) {
    }
}
