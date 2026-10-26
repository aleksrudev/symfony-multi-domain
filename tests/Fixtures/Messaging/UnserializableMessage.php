<?php

declare(strict_types=1);

namespace Tests\Fixtures\Messaging;

final class UnserializableMessage
{
    public function __serialize(): array
    {
        throw new \RuntimeException('Cannot serialize');
    }
}
