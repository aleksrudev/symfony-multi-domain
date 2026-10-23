<?php

declare(strict_types=1);

namespace Tests\Fixtures\Modules\Beta\Infrastructure\Integration\Alpha;

use App\Shared\Application\Bus\Inbox\InboxBusInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Tests\Fixtures\Modules\Alpha\Presentation\Contracts\Dto\ProbeItem;
use Tests\Fixtures\Modules\Alpha\Presentation\Contracts\Event\ProbePublishedEvent;
use Tests\Fixtures\Modules\Beta\Application\Message\RecordProbeMessage;

#[AsEventListener(event: ProbePublishedEvent::class, priority: 10)]
final readonly class EnqueueRecordingProbeListener
{
    public function __construct(private InboxBusInterface $inboxBus)
    {
    }

    public function __invoke(ProbePublishedEvent $event): void
    {
        $this->inboxBus->dispatch(new RecordProbeMessage(
            eventId: $event->eventId,
            eventAt: $event->eventAt,
            source: $event->source,
            type: $event->type,
            schemaVersion: $event->schemaVersion,
            value: $event->value,
            items: array_map(static fn (ProbeItem $item): array => ['code' => $item->code, 'quantity' => $item->quantity], $event->items),
        ));
    }
}
