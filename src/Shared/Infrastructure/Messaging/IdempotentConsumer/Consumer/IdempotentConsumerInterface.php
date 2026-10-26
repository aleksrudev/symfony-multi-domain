<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Messaging\IdempotentConsumer\Consumer;

use App\Shared\Infrastructure\Messaging\IdempotentConsumer\Identity\MessageIdentity;

interface IdempotentConsumerInterface
{
    /** @param callable(): void $reaction */
    public function consume(string $consumerId, MessageIdentity $identity, callable $reaction): void;
}
