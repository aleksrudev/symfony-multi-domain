<?php

declare(strict_types=1);

namespace Tests\Fixtures\Modules\Alpha\Domain;

use App\Shared\Domain\Event\EventInterface;

final readonly class ProbePublishedDomainEvent implements EventInterface
{
    public function __construct(public int $value, public \DateTimeImmutable $eventAt)
    {
    }
}
