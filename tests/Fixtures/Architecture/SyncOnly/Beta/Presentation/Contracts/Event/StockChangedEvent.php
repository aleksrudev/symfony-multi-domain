<?php

declare(strict_types=1);

namespace App\Beta\Presentation\Contracts\Event;

use App\Beta\Presentation\Contracts\Dto\AvailableStock;
use App\Shared\Presentation\Contracts\Event\IntegrationEventInterface;

final readonly class StockChangedEvent implements IntegrationEventInterface
{
    public function __construct(
        public AvailableStock $stock,
        public string $eventId,
        public string $eventAt,
        public string $source,
        public string $type,
        public int $schemaVersion,
    ) {
    }
}
