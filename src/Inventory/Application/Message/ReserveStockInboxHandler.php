<?php

declare(strict_types=1);

namespace App\Inventory\Application\Message;

use App\Inventory\Application\Command\ReserveStock\ReservationItemInput;
use App\Inventory\Application\Command\ReserveStock\ReserveStockCommand;
use App\Shared\Application\Bus\Command\CommandBusInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler(bus: 'inbox.bus')]
final readonly class ReserveStockInboxHandler
{
    public function __construct(
        private CommandBusInterface $commandBus,
    ) {
    }

    public function __invoke(ReserveStockInboxMessage $message): void
    {
        $commandItems = [];
        foreach ($message->items as $item) {
            $commandItems[] = new ReservationItemInput(
                productId: $item->productId,
                quantity: $item->quantity,
            );
        }

        $this->commandBus->dispatch(new ReserveStockCommand(
            orderId: $message->orderId,
            items: $commandItems,
        ));
    }
}

