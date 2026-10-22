<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Messaging;

final readonly class IntegrationEventMetadata
{
    public function __construct(
        public string $eventId,
        public string $eventAt,
        public string $source,
        public string $type,
        public int $schemaVersion,
    ) {
        if ('' === trim($eventId) || '' === trim($source) || '' === trim($type) || $schemaVersion < 1) {
            throw new \InvalidArgumentException('Invalid integration event identity or schema.');
        }
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s.u\Z', $eventAt, new \DateTimeZone('UTC'));
        if (false === $date || $date->format('Y-m-d\TH:i:s.u\Z') !== $eventAt) {
            throw new \InvalidArgumentException('Integration eventAt must be a UTC timestamp with microsecond precision.');
        }
    }
}
