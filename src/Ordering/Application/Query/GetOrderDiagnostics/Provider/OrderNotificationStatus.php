<?php

declare(strict_types=1);

namespace App\Ordering\Application\Query\GetOrderDiagnostics\Provider;

final readonly class OrderNotificationStatus
{
    public function __construct(
        public string $id,
        public string $type,
        public string $status,
        public int $attempts,
        public ?string $errorMessage,
        public string $createdAt,
        public string $updatedAt,
        public ?string $sentAt = null,
    ) {
    }
}
