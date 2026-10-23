<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Messaging;

use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler(bus: 'outbox.bus')]
final readonly class IntegrationEventHandler
{
    public function __construct(private EventDispatcherInterface $dispatcher)
    {
    }

    public function __invoke(IntegrationEventMessage $message): void
    {
        $this->dispatcher->dispatch($message->event);
    }
}
