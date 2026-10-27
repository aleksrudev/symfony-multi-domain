<?php

declare(strict_types=1);

namespace App\Ordering\Domain\Action;

use App\Ordering\Domain\Entity\Order;
use App\Ordering\Domain\Event\OrderRejectedEvent;
use App\Ordering\Domain\Repository\OrderRepositoryInterface;
use App\Shared\Domain\Event\EventBusInterface;

final readonly class RejectOrderAction
{
    public function __construct(
        private OrderRepositoryInterface $orderRepository,
        private EventBusInterface $eventBus,
    ) {
    }

    public function __invoke(Order $order, string $reason): Order
    {
        $order->reject($reason);
        $this->orderRepository->save($order);

        $this->eventBus->dispatch(new OrderRejectedEvent(
            $order,
            $reason,
            new \DateTimeImmutable('now', new \DateTimeZone('UTC')),
        ));

        return $order;
    }
}
