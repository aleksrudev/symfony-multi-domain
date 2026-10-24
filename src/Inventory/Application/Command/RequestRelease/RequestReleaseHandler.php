<?php

declare(strict_types=1);

namespace App\Inventory\Application\Command\RequestRelease;

use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler(bus: 'command.bus')]
final readonly class RequestReleaseHandler
{
    public function __construct(
        private ReleaseQueuePortInterface $queuePort,
    ) {
    }

    public function __invoke(RequestReleaseCommand $command): void
    {
        $this->queuePort->enqueue($command->orderId);
    }
}
