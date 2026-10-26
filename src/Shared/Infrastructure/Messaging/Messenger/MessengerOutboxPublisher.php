<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Messaging\Messenger;

use App\Shared\Infrastructure\Messaging\Outbox\Publisher\OutboxPublisherInterface;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;
use Symfony\Component\Messenger\MessageBusInterface;

#[AsAlias(OutboxPublisherInterface::class)]
final readonly class MessengerOutboxPublisher implements OutboxPublisherInterface
{
    public function __construct(private MessageBusInterface $messageBus)
    {
    }

    public function publish(object $message): void
    {
        $this->messageBus->dispatch($message);
    }
}
