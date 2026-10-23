<?php

declare(strict_types=1);

namespace App\Inventory\Infrastructure\Messaging\Inbox\ReserveStock;

use App\Shared\Application\Bus\Inbox\InboxMessageInterface;

final readonly class ReserveStockInboxMessage implements InboxMessageInterface
{
    /**
     * @param list<ReserveStockInboxItem> $items
     */
    public function __construct(
        public string $orderId,
        public array $items,
    ) {
    }
}

