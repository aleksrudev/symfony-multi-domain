<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Messaging\IntegrationEvent;

use App\Shared\Presentation\Contracts\Event\IntegrationEventInterface;

interface IntegrationEventDispatcherInterface
{
    public function dispatch(IntegrationEventInterface $event): void;
}
