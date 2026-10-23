<?php

declare(strict_types=1);

namespace App\Ordering\Application\Command\ReplaceOrderItems;

use App\Shared\Application\Bus\Command\CommandInterface;

final readonly class ReplaceOrderItemsCommand implements CommandInterface
{
    /**
     * @param list<ReplaceOrderItemInput> $items
     */
    public function __construct(
        public string $orderId,
        public string $buyerId,
        public array $items,
    ) {
    }
}
