<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Bus\Symfony;

use App\Shared\Application\Bus\Inbox\InboxBusInterface;
use App\Shared\Application\Bus\Inbox\InboxMessageInterface;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;
use Symfony\Component\Messenger\MessageBusInterface;

#[AsAlias(InboxBusInterface::class)]
class InboxMessageBus implements InboxBusInterface
{
    public function __construct(
        private MessageBusInterface $inboxBus
    ) {
    }

    public function dispatch(InboxMessageInterface $message): void
    {
        $this->inboxBus->dispatch($message);
    }
}
