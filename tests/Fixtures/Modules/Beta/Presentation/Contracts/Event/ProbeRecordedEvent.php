<?php

declare(strict_types=1);

namespace Tests\Fixtures\Modules\Beta\Presentation\Contracts\Event;

use App\Shared\Presentation\Contracts\Event\IntegrationEventInterface;

final readonly class ProbeRecordedEvent implements IntegrationEventInterface
{
    public function __construct(
        public string $operationId,
        public int $value,
        public string $eventId,
        public string $eventAt,
        public string $source = 'Beta',
        public string $type = 'beta.probe_recorded',
        public int $schemaVersion = 1,
    ) {
    }
}
