<?php

declare(strict_types=1);

namespace App\Shared\Application\Bus\Outbox;

interface OutboxBusInterface
{
    public function publish(OutboxMessageInterface $message): void;
}
