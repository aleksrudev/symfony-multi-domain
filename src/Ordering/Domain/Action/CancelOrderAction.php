<?php

declare(strict_types=1);

namespace App\Ordering\Domain\Action;

use App\Ordering\Domain\Entity\Order;
use App\Ordering\Domain\Event\OrderCancellingEvent;
use App\Ordering\Domain\Repository\OrderRepositoryInterface;
use App\Shared\Domain\Event\EventBusInterface;

final readonly class CancelOrderAction
{
    public function __construct(
        private OrderRepositoryInterface $orderRepository,
        private EventBusInterface $eventBus,
    ) {
    }

    public function __invoke(Order $order): Order
    {
        $order->cancel();
        $this->orderRepository->save($order);

        $event = new OrderCancellingEvent($order, $order->updatedAt());
        $this->eventBus->dispatch($event);

        return $order;
    }
}
