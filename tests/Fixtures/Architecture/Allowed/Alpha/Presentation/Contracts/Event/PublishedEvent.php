<?php

declare(strict_types=1);

namespace App\Alpha\Presentation\Contracts\Event;

use App\Shared\Presentation\Contracts\Event\IntegrationEventInterface;

final readonly class PublishedEvent implements IntegrationEventInterface
{
    public function __construct(
        public string $eventId,
        public string $eventAt,
        public string $source,
        public string $type,
        public int $schemaVersion,
    ) {
    }
}
