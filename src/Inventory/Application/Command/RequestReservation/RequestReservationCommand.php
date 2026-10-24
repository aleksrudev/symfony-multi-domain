<?php

declare(strict_types=1);

namespace App\Inventory\Application\Command\RequestReservation;

use App\Shared\Application\Bus\Command\CommandInterface;

final readonly class RequestReservationCommand implements CommandInterface
{
    /**
     * @param list<RequestReservationItemInput> $items
     */
    public function __construct(
        public string $orderId,
        public array $items,
    ) {
    }
}
