<?php

declare(strict_types=1);

namespace App\Ordering\Application\Query\GetOrderDiagnostics\Result;

final readonly class OrderInventoryDiagnosticResult
{
    public function __construct(
        public string $status,
        public ?string $rejectionReason = null,
    ) {
    }

    /**
     * @return array{status: string, rejectionReason: ?string}
     */
    public function toArray(): array
    {
        return [
            'status' => $this->status,
            'rejectionReason' => $this->rejectionReason,
        ];
    }
}

