<?php

declare(strict_types=1);

namespace App\Ordering\Infrastructure\Messaging\Outbox\OrderNotification;

final readonly class OrderNotificationMessage
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
