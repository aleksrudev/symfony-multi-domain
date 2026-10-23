<?php

declare(strict_types=1);

namespace App\Inventory\Application\Command\ReserveStock;

use App\Inventory\Domain\Action\ReserveStockAction;
use App\Inventory\Domain\Collection\ReservationItemCollection;
use App\Inventory\Domain\Entity\ReservationItem;
use App\Inventory\Domain\ValueObject\OrderId;
use App\Inventory\Domain\ValueObject\ProductId;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler(bus: 'command.bus')]
final readonly class ReserveStockHandler
{
    public function __construct(
        private ReserveStockAction $reserveStockAction,
    ) {
    }

    public function __invoke(ReserveStockCommand $command): void
    {
        $orderId = new OrderId($command->orderId);

        $items = [];
        foreach ($command->items as $itemInput) {
            $items[] = new ReservationItem(
                new ProductId($itemInput->productId),
                $itemInput->quantity,
            );
        }

        ($this->reserveStockAction)($orderId, new ReservationItemCollection($items));
    }
}

