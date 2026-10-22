<?php

declare(strict_types=1);

namespace Tests\Fixtures\Modules\Beta\Infrastructure\Integration\Alpha;

use App\Shared\Infrastructure\Messaging\Attribute\AsIntegrationEventSubscriber;
use App\Shared\Infrastructure\Messaging\IntegrationEventMetadata;
use Tests\Fixtures\Modules\Alpha\Presentation\Contracts\Event\ProbePublishedEvent;

#[AsIntegrationEventSubscriber(id: 'beta.record', event: ProbePublishedEvent::class)]
final class RecordingSubscriber
{
    /** @var list<ProbePublishedEvent> */
    public array $events = [];
    /** @var list<IntegrationEventMetadata> */
    public array $metadata = [];

    public function __invoke(ProbePublishedEvent $event, IntegrationEventMetadata $metadata): void
    {
        $this->events[] = $event;
        $this->metadata[] = $metadata;
    }
}
