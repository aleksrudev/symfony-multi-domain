<?php

declare(strict_types=1);

namespace App\Ordering\Infrastructure\Messaging\Outbox\RequestReservation;

use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler(bus: 'outbox.bus')]
final readonly class RequestReservationOutboxHandler
{
    public function __construct(
        private InventoryReservationGatewayInterface $gateway,
    ) {
    }

    public function __invoke(RequestReservationOutboxMessage $message): void
    {
        $this->gateway->requestReservation($message->orderId, $message->items);
    }
}
