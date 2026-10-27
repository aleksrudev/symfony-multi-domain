<?php

declare(strict_types=1);

namespace App\Notifications\Application\Query\GetNotifications\Result;

final readonly class NotificationItemQueryResult
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

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'orderId' => $this->orderId,
            'type' => $this->type,
            'buyerId' => $this->buyerId,
            'status' => $this->status,
            'attempts' => $this->attempts,
            'payload' => $this->payload,
            'errorMessage' => $this->errorMessage,
            'createdAt' => $this->createdAt,
            'updatedAt' => $this->updatedAt,
            'sentAt' => $this->sentAt,
        ];
    }
}
