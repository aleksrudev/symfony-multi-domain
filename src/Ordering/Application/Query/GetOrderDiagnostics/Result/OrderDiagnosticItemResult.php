<?php

declare(strict_types=1);

namespace App\Ordering\Application\Query\GetOrderDiagnostics\Result;

final readonly class OrderDiagnosticItemResult
{
    public function __construct(
        public string $productId,
        public int $quantity,
        public ?string $productName = null,
        public ?int $priceAmount = null,
    ) {
    }

    /**
     * @return array{productId: string, quantity: int, productName: ?string, priceAmount: ?int}
     */
    public function toArray(): array
    {
        return [
            'productId' => $this->productId,
            'quantity' => $this->quantity,
            'productName' => $this->productName,
            'priceAmount' => $this->priceAmount,
        ];
    }
}

