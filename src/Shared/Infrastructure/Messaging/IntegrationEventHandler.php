<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Messaging;

use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\Exception\UnrecoverableMessageHandlingException;

#[AsMessageHandler(bus: 'outbox.bus')]
final readonly class IntegrationEventHandler
{
    public function __construct(private IntegrationEventRegistry $registry)
    {
    }

    public function __invoke(IntegrationEventEnvelope $message): void
    {
        try {
            $subscription = $this->registry->subscriber($message->subscriberId);
            $definition = $this->registry->definitionForMetadata($message->metadata);
            $eventClass = $definition->eventClass;
            if ($subscription->eventClass !== $eventClass) {
                throw new \LogicException('Integration event does not match the target subscriber schema.');
            }
            $event = $definition->codec->decode($message->payload);
            if (!$event instanceof $eventClass) {
                throw new \UnexpectedValueException('Integration event codec restored a different public DTO type.');
            }
        } catch (\Throwable $exception) {
            throw new UnrecoverableMessageHandlingException('Invalid integration event delivery: '.$exception->getMessage(), previous: $exception);
        }

        $subscription->handle($event, $message->metadata);
    }
}
