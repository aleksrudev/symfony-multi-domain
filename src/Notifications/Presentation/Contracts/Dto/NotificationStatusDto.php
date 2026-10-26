<?php

declare(strict_types=1);

namespace App\Notifications\Presentation\Contracts\Dto;

final readonly class NotificationStatusDto
{
    /**
     * @param array<string, mixed> $payload
     */
    public function __construct(
        public string $id,
        public string $orderId,
        public string $type,
        public string $buyerId,
        public string $status,
        public int $attempts,
        public array $payload,
        public ?string $errorMessage,
        public string $createdAt,
        public string $updatedAt,
        public ?string $sentAt,
    ) {
    }
}
