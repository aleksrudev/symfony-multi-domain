<?php

declare(strict_types=1);

namespace App\Notifications\Application\Query\GetNotificationStatus;

use App\Shared\Application\Bus\Query\QueryInterface;

final readonly class GetNotificationStatusQuery implements QueryInterface
{
    public function __construct(
        public string $orderId,
        public string $type,
    ) {
    }
}
