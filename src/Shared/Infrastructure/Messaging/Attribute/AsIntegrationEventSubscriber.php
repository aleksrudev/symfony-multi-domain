<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Messaging\Attribute;

#[\Attribute(\Attribute::TARGET_CLASS)]
final readonly class AsIntegrationEventSubscriber
{
    /** @param class-string $event */
    public function __construct(public string $id, public string $event)
    {
    }
}
