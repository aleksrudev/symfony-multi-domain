<?php

declare(strict_types=1);

namespace Tests\Fixtures\Modules\Beta\Application\Command\FailProbe;

interface FailProbeStoreInterface
{
    public function record(FailProbeCommand $command): void;
}
