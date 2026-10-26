<?php

declare(strict_types=1);

namespace Tests\Fixtures\Modules\Alpha\Application;

use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Tests\Fixtures\Modules\Alpha\Domain\ProbeFailure;

final class ProbeHandler
{
    public int $calls = 0;

    public function __construct(private readonly ProbeStoreInterface $store)
    {
    }

    #[AsMessageHandler(bus: 'command.bus')]
    public function command(ProbeCommand $command): void
    {
        $this->store->append($command->value);
        if ($command->fail) {
            throw new ProbeFailure();
        }
        ++$this->calls;
    }

    #[AsMessageHandler(bus: 'query.bus')]
    public function query(ProbeQuery $query): int
    {
        return $this->calls;
    }
}
