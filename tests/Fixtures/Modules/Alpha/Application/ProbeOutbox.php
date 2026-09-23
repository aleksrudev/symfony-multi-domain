<?php

declare(strict_types=1);

namespace Tests\Fixtures\Modules\Alpha\Application;

use App\Shared\Application\Bus\Outbox\OutboxMessageInterface;

final readonly class ProbeOutbox implements OutboxMessageInterface
{
    public function __construct(public int $value)
    {
    }
}
