<?php

declare(strict_types=1);

namespace Tests\Fixtures\Modules\Beta\Infrastructure\Persistence;

use Doctrine\DBAL\Connection;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;
use Tests\Fixtures\Modules\Beta\Application\Command\RecordProbe\RecordProbeCommand;
use Tests\Fixtures\Modules\Beta\Application\Command\RecordProbe\RecordProbeStoreInterface;

#[AsAlias(RecordProbeStoreInterface::class)]
final readonly class RecordProbeStore implements RecordProbeStoreInterface
{
    public function __construct(private Connection $connection)
    {
    }

    public function record(RecordProbeCommand $command): void
    {
        $this->connection->insert('beta.probe_recorded', ['event_id' => $command->operationId, 'occurred_at' => $command->occurredAt, 'value' => $command->value, 'items' => json_encode($command->items, JSON_THROW_ON_ERROR)]);
    }
}
