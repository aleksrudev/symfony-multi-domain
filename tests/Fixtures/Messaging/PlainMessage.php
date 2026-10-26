<?php

declare(strict_types=1);

namespace Tests\Fixtures\Messaging;

final readonly class PlainMessage
{
    public function __construct(public string $operationId, public NestedMessageValue $nested, public string $binary)
    {
    }
}
