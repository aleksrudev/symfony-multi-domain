<?php

declare(strict_types=1);

namespace Tests\Fixtures\Modules\Beta\Application\Command\FailProbe;

use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler(bus: 'command.bus')]
final readonly class FailProbeHandler
{
    public function __construct(private FailProbeStoreInterface $store)
    {
    }

    public function __invoke(FailProbeCommand $command): void
    {
        $this->store->record($command);
    }
}
