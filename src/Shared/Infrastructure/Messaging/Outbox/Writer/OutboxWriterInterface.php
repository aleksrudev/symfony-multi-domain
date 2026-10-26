<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Messaging\Outbox\Writer;

interface OutboxWriterInterface
{
    public function append(object $message): void;
}
