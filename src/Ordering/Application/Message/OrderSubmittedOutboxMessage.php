<?php

declare(strict_types=1);

namespace App\Ordering\Application\Message;

use App\Shared\Application\Bus\Outbox\OutboxMessageInterface;

final readonly class OrderSubmittedOutboxMessage implements OutboxMessageInterface
{
    /**
     * @param list<array{productId: string, quantity: int}> $items
     */
    public function __construct(
        public string $orderId,
        public string $buyerId,
        public array $items,
        public int $totalAmount,
        public string $currency,
        public string $occurredAt,
    ) {
    }
}
