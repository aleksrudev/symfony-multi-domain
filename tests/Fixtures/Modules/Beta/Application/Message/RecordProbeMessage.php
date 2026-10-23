<?php

declare(strict_types=1);

namespace Tests\Fixtures\Modules\Beta\Application\Message;

use App\Shared\Application\Bus\Inbox\InboxMessageInterface;

final readonly class RecordProbeMessage implements InboxMessageInterface
{
    /** @param list<array{code: string, quantity: int}> $items */
    public function __construct(
        public string $eventId,
        public string $eventAt,
        public string $source,
        public string $type,
        public int $schemaVersion,
        public int $value,
        public array $items,
    ) {
    }
}
