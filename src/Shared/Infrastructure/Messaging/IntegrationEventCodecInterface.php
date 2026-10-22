<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Messaging;

interface IntegrationEventCodecInterface
{
    /** Encode the public DTO as a JSON object in its published schema. */
    public function encode(object $event): string;

    /** Restore the public DTO, validating the published schema. */
    public function decode(string $payload): object;
}
