<?php

declare(strict_types=1);

namespace App\Ordering\Infrastructure\Messaging\Outbox\RequestReservation;

use App\Shared\Application\Bus\Outbox\OutboxMessageInterface;

final readonly class RequestReservationOutboxMessage implements OutboxMessageInterface
{
    /**
     * @param list<RequestReservationOutboxItem> $items
     */
    public function __construct(
        public string $orderId,
        public array $items,
    ) {
    }
}
