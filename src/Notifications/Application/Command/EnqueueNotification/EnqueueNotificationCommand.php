<?php

declare(strict_types=1);

namespace App\Notifications\Application\Command\EnqueueNotification;

use App\Shared\Application\Bus\Command\CommandInterface;

final readonly class EnqueueNotificationCommand implements CommandInterface
{
    /**
     * @param array<string, mixed> $payload
     */
    public function __construct(
        public string $orderId,
        public string $type,
        public string $buyerId,
        public array $payload = [],
    ) {
    }
}
