<?php

declare(strict_types=1);

namespace App\Ordering\Presentation\Contracts\Event;

use App\Shared\Presentation\Contracts\Event\IntegrationEventInterface;

final readonly class OrderCancellationFinalizedEvent implements IntegrationEventInterface
{
    public function __construct(
        public string $eventId,
        public string $eventAt,
        public string $type,
        public string $source,
        public int $schemaVersion,
        public string $orderId,
        public string $buyerId,
    ) {
    }
}

