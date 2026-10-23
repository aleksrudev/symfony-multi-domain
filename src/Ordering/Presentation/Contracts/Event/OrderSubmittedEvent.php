<?php

declare(strict_types=1);

namespace App\Ordering\Presentation\Contracts\Event;

use App\Ordering\Presentation\Contracts\Dto\SubmittedOrderItem;
use App\Shared\Presentation\Contracts\Event\IntegrationEventInterface;

final readonly class OrderSubmittedEvent implements IntegrationEventInterface
{
    /**
     * @param list<SubmittedOrderItem> $items
     */
    public function __construct(
        public string $eventId,
        public string $eventAt,
        public string $type,
        public string $source,
        public int $schemaVersion,
        public string $orderId,
        public string $buyerId,
        public array $items,
        public int $totalAmount,
        public string $currency,
    ) {
    }
}

