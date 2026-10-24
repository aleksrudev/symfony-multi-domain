<?php

declare(strict_types=1);

namespace App\Inventory\Infrastructure\Messaging\Inbox\ReserveStock;

final readonly class ReserveStockInboxItem
{
    public function __construct(
        public string $productId,
        public int $quantity,
    ) {
    }
}
