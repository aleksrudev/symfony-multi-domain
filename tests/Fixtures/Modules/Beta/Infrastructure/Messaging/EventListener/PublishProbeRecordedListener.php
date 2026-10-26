<?php

declare(strict_types=1);

namespace Tests\Fixtures\Modules\Beta\Infrastructure\Messaging\EventListener;

use App\Shared\Infrastructure\Messaging\IntegrationEvent\IntegrationEventDispatcherInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Uid\Uuid;
use Tests\Fixtures\Modules\Beta\Domain\ProbeRecorded;
use Tests\Fixtures\Modules\Beta\Presentation\Contracts\Event\ProbeRecordedEvent;

#[AsEventListener(event: ProbeRecorded::class)]
final readonly class PublishProbeRecordedListener
{
    public function __construct(private IntegrationEventDispatcherInterface $dispatcher)
    {
    }

    public function __invoke(ProbeRecorded $event): void
    {
        $this->dispatcher->dispatch(new ProbeRecordedEvent($event->operationId, $event->value, Uuid::v4()->toRfc4122(), $event->eventAt->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.u\Z')));
    }
}
