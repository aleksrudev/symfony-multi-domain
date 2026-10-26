<?php

declare(strict_types=1);

namespace App\Notifications\Presentation\Contracts\Dto;

final readonly class EnqueueNotificationInput
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
