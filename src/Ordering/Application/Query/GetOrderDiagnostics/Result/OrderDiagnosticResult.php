<?php

declare(strict_types=1);

namespace App\Ordering\Application\Query\GetOrderDiagnostics\Result;

final readonly class OrderDiagnosticResult
{
    /**
     * @param list<OrderDiagnosticItemResult> $items
     */
    public function __construct(
        public string $id,
        public string $buyerId,
        public string $status,
        public array $items,
        public string $createdAt,
        public string $updatedAt,
        public ?int $totalAmount = null,
        public ?string $currency = null,
        public ?string $rejectionReason = null,
    ) {
    }

    /**
     * @return array{
     *     id: string,
     *     buyerId: string,
     *     status: string,
     *     items: list<array{productId: string, quantity: int, productName: ?string, priceAmount: ?int}>,
     *     totalAmount: ?int,
     *     currency: ?string,
     *     rejectionReason: ?string,
     *     createdAt: string,
     *     updatedAt: string
     * }
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'buyerId' => $this->buyerId,
            'status' => $this->status,
            'items' => array_map(static fn (OrderDiagnosticItemResult $item): array => $item->toArray(), $this->items),
            'totalAmount' => $this->totalAmount,
            'currency' => $this->currency,
            'rejectionReason' => $this->rejectionReason,
            'createdAt' => $this->createdAt,
            'updatedAt' => $this->updatedAt,
        ];
    }
}

