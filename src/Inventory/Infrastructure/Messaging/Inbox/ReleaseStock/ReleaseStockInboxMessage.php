<?php

declare(strict_types=1);

namespace App\Inventory\Infrastructure\Messaging\Inbox\ReleaseStock;

use App\Shared\Application\Bus\Inbox\InboxMessageInterface;

final readonly class ReleaseStockInboxMessage implements InboxMessageInterface
{
    public function __construct(
        public string $orderId,
    ) {
    }
}
