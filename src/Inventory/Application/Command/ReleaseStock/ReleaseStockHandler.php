<?php

declare(strict_types=1);

namespace App\Inventory\Application\Command\ReleaseStock;

use App\Inventory\Domain\Action\ReleaseStockAction;
use App\Inventory\Domain\ValueObject\OrderId;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler(bus: 'command.bus')]
final readonly class ReleaseStockHandler
{
    public function __construct(
        private ReleaseStockAction $releaseStockAction,
    ) {
    }

    public function __invoke(ReleaseStockCommand $command): void
    {
        $orderId = new OrderId($command->orderId);
        ($this->releaseStockAction)($orderId);
    }
}

