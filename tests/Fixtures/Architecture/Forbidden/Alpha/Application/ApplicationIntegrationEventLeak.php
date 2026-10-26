<?php

declare(strict_types=1);

namespace App\Alpha\Application;

use App\Shared\Presentation\Contracts\Event\IntegrationEventInterface;

final readonly class ApplicationIntegrationEventLeak implements IntegrationEventInterface
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
