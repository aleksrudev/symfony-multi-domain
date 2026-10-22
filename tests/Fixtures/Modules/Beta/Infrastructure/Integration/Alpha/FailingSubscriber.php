<?php

declare(strict_types=1);

namespace Tests\Fixtures\Modules\Beta\Infrastructure\Integration\Alpha;

use App\Shared\Infrastructure\Messaging\Attribute\AsIntegrationEventSubscriber;
use App\Shared\Infrastructure\Messaging\IntegrationEventMetadata;
use Tests\Fixtures\Modules\Alpha\Presentation\Contracts\Event\ProbePublishedEvent;

#[AsIntegrationEventSubscriber(id: 'beta.fail', event: ProbePublishedEvent::class)]
final class FailingSubscriber
{
    public int $calls = 0;

    public function __invoke(ProbePublishedEvent $event, IntegrationEventMetadata $metadata): void
    {
        ++$this->calls;
        throw new \RuntimeException('Fixture subscriber is unavailable.');
    }
}
