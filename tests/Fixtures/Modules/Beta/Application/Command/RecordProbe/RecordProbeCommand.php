<?php

declare(strict_types=1);

namespace Tests\Fixtures\Modules\Beta\Application\Command\RecordProbe;

use App\Shared\Application\Bus\Command\CommandInterface;

final readonly class RecordProbeCommand implements CommandInterface
{
    /** @param list<array{code: string, quantity: int}> $items */
    public function __construct(public string $operationId, public string $occurredAt, public int $value, public array $items, public bool $failAfterPublish = false)
    {
    }
}
