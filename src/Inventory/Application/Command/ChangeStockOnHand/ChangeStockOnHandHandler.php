<?php

declare(strict_types=1);

namespace App\Inventory\Application\Command\ChangeStockOnHand;

use App\Inventory\Domain\Action\ChangeStockOnHandAction;
use App\Inventory\Domain\Exception\StockPositionNotFoundException;
use App\Inventory\Domain\ValueObject\ProductId;

final readonly class ChangeStockOnHandHandler
{
    public function __construct(
        private ChangeStockOnHandAction $changeStockOnHandAction,
    ) {
    }

    public function __invoke(ChangeStockOnHandCommand $command): void
    {
        try {
            $productId = new ProductId($command->productId);
        } catch (\InvalidArgumentException) {
            throw new StockPositionNotFoundException($command->productId);
        }

        ($this->changeStockOnHandAction)($productId, $command->onHand);
    }
}

