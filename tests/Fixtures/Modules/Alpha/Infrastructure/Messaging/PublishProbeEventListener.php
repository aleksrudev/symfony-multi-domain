<?php

declare(strict_types=1);

namespace Tests\Fixtures\Modules\Alpha\Infrastructure\Messaging;

use App\Shared\Infrastructure\Messaging\IntegrationEvent\IntegrationEventDispatcherInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Uid\Uuid;
use Tests\Fixtures\Modules\Alpha\Domain\ProbePublishedDomainEvent;
use Tests\Fixtures\Modules\Alpha\Presentation\Contracts\Dto\ProbeItem;
use Tests\Fixtures\Modules\Alpha\Presentation\Contracts\Event\ProbePublishedEvent;

#[AsEventListener(event: ProbePublishedDomainEvent::class)]
final readonly class PublishProbeEventListener
{
    public function __construct(private IntegrationEventDispatcherInterface $dispatcher)
    {
    }

    public function __invoke(ProbePublishedDomainEvent $event): void
    {
        $this->dispatcher->dispatch(new ProbePublishedEvent(
            value: $event->value,
            items: [new ProbeItem('item-1', 3)],
            eventId: Uuid::v4()->toRfc4122(),
            eventAt: $event->eventAt->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.u\Z'),
        ));
    }
}
