<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Messaging\IntegrationEvent;

use App\Shared\Application\Bus\Outbox\OutboxMessageInterface;
use App\Shared\Presentation\Contracts\Event\IntegrationEventInterface;

final readonly class IntegrationEventMessage implements OutboxMessageInterface
{
    public function __construct(public IntegrationEventInterface $event)
    {
    }
}
