<?php

declare(strict_types=1);

namespace Tests\Fixtures\Messaging;

final readonly class NestedMessageValue
{
    public function __construct(public string $value)
    {
    }
}
