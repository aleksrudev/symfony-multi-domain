<?php

declare(strict_types=1);

namespace App\Shared\Application\Bus\Inbox;

interface InboxBusInterface
{
    public function dispatch(InboxMessageInterface $message): void;
}
