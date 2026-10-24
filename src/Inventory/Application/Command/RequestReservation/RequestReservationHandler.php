<?php

declare(strict_types=1);

namespace App\Inventory\Application\Command\RequestReservation;

use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler(bus: 'command.bus')]
final readonly class RequestReservationHandler
{
    public function __construct(
        private ReservationQueuePortInterface $queuePort,
    ) {
    }

    public function __invoke(RequestReservationCommand $command): void
    {
        $this->queuePort->enqueue($command->orderId, $command->items);
    }
}
