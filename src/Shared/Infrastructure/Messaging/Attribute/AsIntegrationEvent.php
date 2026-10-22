<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Messaging\Attribute;

#[\Attribute(\Attribute::TARGET_CLASS)]
final readonly class AsIntegrationEvent
{
    /** @param class-string $event */
    public function __construct(
        public string $event,
        public string $source,
        public string $type,
        public int $schemaVersion = 1,
    ) {
    }
}
