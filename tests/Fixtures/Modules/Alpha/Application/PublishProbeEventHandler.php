<?php

declare(strict_types=1);

namespace Tests\Fixtures\Modules\Alpha\Application;

use App\Shared\Domain\Event\EventBusInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Tests\Fixtures\Modules\Alpha\Domain\ProbeFailure;
use Tests\Fixtures\Modules\Alpha\Domain\ProbePublishedDomainEvent;

#[AsMessageHandler(bus: 'command.bus')]
final readonly class PublishProbeEventHandler
{
    public function __construct(private ProbeStoreInterface $store, private EventBusInterface $eventBus)
    {
    }

    public function __invoke(PublishProbeEventCommand $command): void
    {
        $this->store->append($command->value);
        $this->eventBus->dispatch(new ProbePublishedDomainEvent($command->value, new \DateTimeImmutable('2026-10-22T12:30:00.123456+03:00')));
        if ($command->fail) {
            throw new ProbeFailure();
        }
    }
}
