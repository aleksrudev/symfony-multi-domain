<?php

declare(strict_types=1);

namespace Tests\Fixtures\Modules\Alpha\Presentation\Contracts\Event;

use App\Shared\Presentation\Contracts\Event\IntegrationEventInterface;
use Tests\Fixtures\Modules\Alpha\Presentation\Contracts\Dto\ProbeItem;

final readonly class ProbePublishedEvent implements IntegrationEventInterface
{
    /** @param list<ProbeItem> $items */
    public function __construct(
        public int $value,
        public array $items,
        public string $eventId,
        public string $eventAt,
        public string $source = 'Alpha',
        public string $type = 'alpha.published',
        public int $schemaVersion = 1,
    ) {
    }
}
