<?php

declare(strict_types=1);

namespace App\Inventory\Infrastructure\Messaging\Inbox\ReleaseStock;

use App\Inventory\Application\Command\RequestRelease\ReleaseQueuePortInterface;
use App\Shared\Application\Bus\Inbox\InboxBusInterface;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

#[AsAlias(ReleaseQueuePortInterface::class)]
final readonly class ReleaseInboxQueueAdapter implements ReleaseQueuePortInterface
{
    public function __construct(
        private InboxBusInterface $inboxBus,
    ) {
    }

    public function enqueue(string $orderId): void
    {
        $this->inboxBus->dispatch(new ReleaseStockInboxMessage(
            orderId: $orderId,
        ));
    }
}
