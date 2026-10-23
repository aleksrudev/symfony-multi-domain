<?php

declare(strict_types=1);

namespace App\Inventory\Application\Message;

use App\Inventory\Application\Command\ReleaseStock\ReleaseStockCommand;
use App\Shared\Application\Bus\Command\CommandBusInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler(bus: 'inbox.bus')]
final readonly class ReleaseStockInboxHandler
{
    public function __construct(
        private CommandBusInterface $commandBus,
    ) {
    }

    public function __invoke(ReleaseStockInboxMessage $message): void
    {
        $this->commandBus->dispatch(new ReleaseStockCommand(
            orderId: $message->orderId,
        ));
    }
}

