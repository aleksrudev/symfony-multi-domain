<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Messaging\Outbox\Publisher;

interface OutboxPublisherInterface
{
    public function publish(object $message): void;
}
