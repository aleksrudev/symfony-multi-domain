<?php

declare(strict_types=1);

namespace Tests\Fixtures\Modules\Beta\Application\Command\RecordProbe;

interface RecordProbeStoreInterface
{
    public function record(RecordProbeCommand $command): void;
}
