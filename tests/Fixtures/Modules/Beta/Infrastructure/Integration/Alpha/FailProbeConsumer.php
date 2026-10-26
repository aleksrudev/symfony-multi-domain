<?php

declare(strict_types=1);

namespace Tests\Fixtures\Modules\Beta\Infrastructure\Integration\Alpha;

use App\Shared\Application\Bus\Command\CommandBusInterface;
use App\Shared\Infrastructure\Messaging\IdempotentConsumer\Consumer\IdempotentConsumerInterface;
use App\Shared\Infrastructure\Messaging\IdempotentConsumer\Identity\MessageIdentity;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Tests\Fixtures\Modules\Alpha\Presentation\Contracts\Event\ProbePublishedEvent;
use Tests\Fixtures\Modules\Beta\Application\Command\FailProbe\FailProbeCommand;

#[AsMessageHandler(bus: 'event.bus', priority: 0)]
final readonly class FailProbeConsumer
{
    public function __construct(private IdempotentConsumerInterface $consumer, private CommandBusInterface $commands)
    {
    }

    public function __invoke(ProbePublishedEvent $event): void
    {
        $this->consumer->consume('beta.fail-probe', new MessageIdentity($event->source, $event->eventId), function () use ($event): void {
            $this->commands->dispatch(new FailProbeCommand($event->source.'-'.$event->eventId, $event->value));
        });
    }
}
