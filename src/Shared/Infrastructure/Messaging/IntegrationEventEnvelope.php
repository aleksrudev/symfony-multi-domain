<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Messaging;

use App\Shared\Application\Bus\Outbox\OutboxMessageInterface;

final readonly class IntegrationEventEnvelope implements OutboxMessageInterface
{
    public function __construct(
        public IntegrationEventMetadata $metadata,
        public string $payload,
        public string $subscriberId,
    ) {
    }
}
