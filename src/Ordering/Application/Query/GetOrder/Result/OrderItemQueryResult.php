<?php

declare(strict_types=1);

namespace App\Ordering\Application\Query\GetOrder\Result;

final readonly class OrderItemQueryResult
{
    public function __construct(
        public string $productId,
        public int $quantity,
        public ?string $productName = null,
        public ?int $priceAmount = null,
        public ?string $currency = null,
    ) {
    }

    /**
     * @return array{productId: string, quantity: int, productName?: string, priceAmount?: int, currency?: string, name?: string, price?: int}
     */
    public function toArray(): array
    {
        $data = [
            'productId' => $this->productId,
            'quantity' => $this->quantity,
        ];

        if (null !== $this->productName) {
            $data['productName'] = $this->productName;
            $data['name'] = $this->productName;
        }

        if (null !== $this->priceAmount) {
            $data['priceAmount'] = $this->priceAmount;
            $data['price'] = $this->priceAmount;
        }

        if (null !== $this->currency) {
            $data['currency'] = $this->currency;
        }

        return $data;
    }
}
