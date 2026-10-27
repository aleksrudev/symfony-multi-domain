<?php

declare(strict_types=1);

namespace App\Ordering\Application\Query\GetOrderDiagnostics\Result;

final readonly class OrderNotificationDiagnosticResult
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

    /**
     * @return array{
     *     id: string,
     *     type: string,
     *     status: string,
     *     attempts: int,
     *     errorMessage: ?string,
     *     createdAt: string,
     *     updatedAt: string,
     *     sentAt: ?string
     * }
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type,
            'status' => $this->status,
            'attempts' => $this->attempts,
            'errorMessage' => $this->errorMessage,
            'createdAt' => $this->createdAt,
            'updatedAt' => $this->updatedAt,
            'sentAt' => $this->sentAt,
        ];
    }
}

