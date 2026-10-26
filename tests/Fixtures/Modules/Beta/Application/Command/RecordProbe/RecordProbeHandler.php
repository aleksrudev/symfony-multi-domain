<?php

declare(strict_types=1);

namespace Tests\Fixtures\Modules\Beta\Application\Command\RecordProbe;

use App\Shared\Domain\Event\EventBusInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Tests\Fixtures\Modules\Beta\Domain\ProbeRecorded;

#[AsMessageHandler(bus: 'command.bus')]
final readonly class RecordProbeHandler
{
    public function __construct(private RecordProbeStoreInterface $store, private EventBusInterface $eventBus)
    {
    }

    public function __invoke(RecordProbeCommand $command): void
    {
        $this->store->record($command);
        $this->eventBus->dispatch(new ProbeRecorded($command->operationId, $command->value, new \DateTimeImmutable('2026-10-26T12:00:00.654321+03:00')));
        if ($command->failAfterPublish) {
            throw new \RuntimeException('Fixture reaction failure after publication.');
        }
    }
}
