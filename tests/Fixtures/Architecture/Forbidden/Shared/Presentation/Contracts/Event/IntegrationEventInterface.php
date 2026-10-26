<?php

declare(strict_types=1);

namespace App\Shared\Presentation\Contracts\Event;

interface IntegrationEventInterface
{
    public string $eventId { get; }
    public string $eventAt { get; }
    public string $source { get; }
    public string $type { get; }
    public int $schemaVersion { get; }
}
