<?php

declare(strict_types=1);

namespace App\Ordering\Application\Command\ReplaceOrderItems\Input;

final readonly class ReplaceOrderItemInput
{
    public function __construct(
        public string $productId,
        public int $quantity,
    ) {
    }
}
