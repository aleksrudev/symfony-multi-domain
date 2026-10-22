<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Messaging;

final readonly class IntegrationEventSubscription
{
    private \Closure $handler;

    /** @param class-string $eventClass */
    public function __construct(public string $id, public string $eventClass, callable $handler)
    {
        if ('' === trim($id)) {
            throw new \LogicException('Integration event subscriber id must be nonempty.');
        }
        $this->handler = \Closure::fromCallable($handler);
    }

    public function handle(object $event, IntegrationEventMetadata $metadata): void
    {
        ($this->handler)($event, $metadata);
    }
}
