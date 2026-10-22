<?php

declare(strict_types=1);

namespace Tests\Fixtures\Modules\Alpha\Presentation\Contracts\Event;

use Tests\Fixtures\Modules\Alpha\Presentation\Contracts\Dto\ProbeItem;

final readonly class ProbePublishedEvent
{
    /** @param list<ProbeItem> $items */
    public function __construct(public int $value, public array $items)
    {
    }
}
