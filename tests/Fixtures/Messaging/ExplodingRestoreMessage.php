<?php

declare(strict_types=1);

namespace Tests\Fixtures\Messaging;

final class ExplodingRestoreMessage
{
    /** @param array<mixed> $data */
    public function __unserialize(array $data): void
    {
        throw new \RuntimeException('Restore refused');
    }
}
