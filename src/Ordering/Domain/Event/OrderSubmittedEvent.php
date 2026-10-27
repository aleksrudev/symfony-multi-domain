<?php

declare(strict_types=1);

namespace App\Ordering\Domain\Event;

use App\Ordering\Domain\Entity\Order;
use App\Shared\Domain\Event\EventInterface;

final readonly class OrderSubmittedEvent implements EventInterface
{
    public function __construct(
        public Order $order,
        public \DateTimeImmutable $eventAt,
    ) {
    }
}
