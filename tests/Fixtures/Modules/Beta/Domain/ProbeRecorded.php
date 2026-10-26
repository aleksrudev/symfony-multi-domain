<?php

declare(strict_types=1);

namespace Tests\Fixtures\Modules\Beta\Domain;

use App\Shared\Domain\Event\EventInterface;

final readonly class ProbeRecorded implements EventInterface
{
    public function __construct(public string $operationId, public int $value, public \DateTimeImmutable $eventAt)
    {
    }
}
