<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Messaging;

use App\Shared\Application\Bus\Outbox\OutboxBusInterface;
use App\Shared\Presentation\Contracts\Event\IntegrationEventInterface;

final readonly class IntegrationEventDispatcher
{
    public function __construct(private OutboxBusInterface $outboxBus)
    {
    }

    public function dispatch(IntegrationEventInterface $event): void
    {
        $this->outboxBus->publish(new IntegrationEventMessage($event));
    }
}
