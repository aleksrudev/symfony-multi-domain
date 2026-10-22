<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Messaging;

final readonly class IntegrationEventRegistry
{
    /** @var array<class-string, IntegrationEventDefinition> */
    private array $events;
    /** @var array<string, IntegrationEventDefinition> */
    private array $schemas;
    /** @var array<string, IntegrationEventSubscription> */
    private array $subscribers;

    /**
     * @param list<IntegrationEventDefinition>   $definitions
     * @param list<IntegrationEventSubscription> $subscriptions
     */
    public function __construct(array $definitions = [], array $subscriptions = [])
    {
        $events = $schemas = $subscribers = [];
        foreach ($definitions as $definition) {
            $key = $this->schemaKey($definition->source, $definition->type, $definition->schemaVersion);
            if (isset($events[$definition->eventClass]) || isset($schemas[$key])) {
                throw new \LogicException(sprintf('Duplicate integration event definition for "%s".', $definition->eventClass));
            }
            $events[$definition->eventClass] = $schemas[$key] = $definition;
        }
        foreach ($subscriptions as $subscription) {
            if (isset($subscribers[$subscription->id])) {
                throw new \LogicException(sprintf('Duplicate integration event subscriber "%s".', $subscription->id));
            }
            if (!isset($events[$subscription->eventClass])) {
                throw new \LogicException(sprintf('Subscriber "%s" uses an unregistered integration event.', $subscription->id));
            }
            $subscribers[$subscription->id] = $subscription;
        }
        $this->events = $events;
        $this->schemas = $schemas;
        $this->subscribers = $subscribers;
    }

    public function definitionForEvent(object $event): IntegrationEventDefinition
    {
        return $this->events[$event::class] ?? throw new \LogicException(sprintf('Unregistered integration event "%s".', $event::class));
    }

    public function definitionForMetadata(IntegrationEventMetadata $metadata): IntegrationEventDefinition
    {
        return $this->schemas[$this->schemaKey($metadata->source, $metadata->type, $metadata->schemaVersion)]
            ?? throw new \LogicException(sprintf('Unknown integration event schema "%s/%s/v%d".', $metadata->source, $metadata->type, $metadata->schemaVersion));
    }

    /** @return list<IntegrationEventSubscription> */
    public function subscribersForEvent(object $event): array
    {
        return array_values(array_filter($this->subscribers, static fn (IntegrationEventSubscription $subscription): bool => $subscription->eventClass === $event::class));
    }

    public function subscriber(string $id): IntegrationEventSubscription
    {
        return $this->subscribers[$id] ?? throw new \LogicException(sprintf('Unknown integration event subscriber "%s".', $id));
    }

    private function schemaKey(string $source, string $type, int $schemaVersion): string
    {
        return json_encode([$source, $type, $schemaVersion], JSON_THROW_ON_ERROR);
    }
}
