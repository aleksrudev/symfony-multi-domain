<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Messaging;

use App\Shared\Application\Bus\Outbox\OutboxBusInterface;
use Doctrine\DBAL\Connection;
use Symfony\Component\Uid\Uuid;

final readonly class IntegrationEventPublisher
{
    public function __construct(
        private IntegrationEventRegistry $registry,
        private OutboxBusInterface $outboxBus,
        private Connection $connection,
    ) {
    }

    public function publish(object $event, \DateTimeImmutable $eventAt): IntegrationEventMetadata
    {
        if (!$this->connection->isTransactionActive()) {
            throw new \LogicException('Integration events must be published inside the default Doctrine transaction.');
        }
        $definition = $this->registry->definitionForEvent($event);
        $payload = $definition->codec->encode($event);
        if (!json_decode($payload, flags: JSON_THROW_ON_ERROR) instanceof \stdClass) {
            throw new \UnexpectedValueException('Integration event codec must encode a JSON object.');
        }
        $metadata = new IntegrationEventMetadata(
            eventId: Uuid::v4()->toRfc4122(),
            eventAt: $eventAt->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.u\Z'),
            source: $definition->source,
            type: $definition->type,
            schemaVersion: $definition->schemaVersion,
        );
        foreach ($this->registry->subscribersForEvent($event) as $subscription) {
            $this->outboxBus->publish(new IntegrationEventEnvelope($metadata, $payload, $subscription->id));
        }

        return $metadata;
    }
}
