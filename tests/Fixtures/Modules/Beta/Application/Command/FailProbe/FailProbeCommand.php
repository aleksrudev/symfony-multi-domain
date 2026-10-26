<?php

declare(strict_types=1);

namespace Tests\Fixtures\Modules\Beta\Application\Command\FailProbe;

use App\Shared\Application\Bus\Command\CommandInterface;

final readonly class FailProbeCommand implements CommandInterface
{
    public function __construct(public string $operationId, public int $value)
    {
    }
}
