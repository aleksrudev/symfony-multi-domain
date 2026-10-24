<?php

declare(strict_types=1);

namespace App\Inventory\Infrastructure\Messaging\Inbox\ReserveStock;

use App\Inventory\Application\Command\RequestReservation\ReservationQueuePortInterface;
use App\Shared\Application\Bus\Inbox\InboxBusInterface;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

#[AsAlias(ReservationQueuePortInterface::class)]
final readonly class ReservationInboxQueueAdapter implements ReservationQueuePortInterface
{
    public function __construct(
        private InboxBusInterface $inboxBus,
    ) {
    }

    public function enqueue(string $orderId, array $items): void
    {
        $inboxItems = [];
        foreach ($items as $item) {
            $inboxItems[] = new ReserveStockInboxItem(
                productId: $item->productId,
                quantity: $item->quantity,
            );
        }

        $this->inboxBus->dispatch(new ReserveStockInboxMessage(
            orderId: $orderId,
            items: $inboxItems,
        ));
    }
}
