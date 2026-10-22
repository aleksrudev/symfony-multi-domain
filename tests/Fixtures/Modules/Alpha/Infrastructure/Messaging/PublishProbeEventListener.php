<?php

declare(strict_types=1);

namespace Tests\Fixtures\Modules\Alpha\Infrastructure\Messaging;

use App\Shared\Infrastructure\Messaging\IntegrationEventPublisher;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Tests\Fixtures\Modules\Alpha\Domain\ProbePublishedDomainEvent;
use Tests\Fixtures\Modules\Alpha\Presentation\Contracts\Dto\ProbeItem;
use Tests\Fixtures\Modules\Alpha\Presentation\Contracts\Event\ProbePublishedEvent;

#[AsEventListener(event: ProbePublishedDomainEvent::class)]
final readonly class PublishProbeEventListener
{
    public function __construct(private IntegrationEventPublisher $publisher)
    {
    }

    public function __invoke(ProbePublishedDomainEvent $event): void
    {
        $this->publisher->publish(new ProbePublishedEvent($event->value, [new ProbeItem('item-1', 3)]), $event->eventAt);
    }
}
