<?php

declare(strict_types=1);

namespace Tests\Fixtures\Messaging\Integration;

use App\Shared\Presentation\Contracts\Event\IntegrationEventInterface;

// Deliberately invalid payload to exercise a source snapshot failure.
final readonly class UnserializableEvent implements IntegrationEventInterface
{
    public function __construct(
        public string $eventId,
        public \Closure $payload,
        public string $eventAt = '2026-10-26T09:00:00.123456Z',
        public string $source = 'Alpha',
        public string $type = 'alpha.invalid_snapshot',
        public int $schemaVersion = 1,
    ) {
    }
}
