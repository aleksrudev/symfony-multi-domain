<?php

declare(strict_types=1);

namespace App\Ordering\Domain\Action;

use App\Ordering\Domain\Entity\Order;
use App\Ordering\Domain\Event\OrderCancellationFinalizedEvent;
use App\Ordering\Domain\Repository\OrderRepositoryInterface;
use App\Shared\Domain\Event\EventBusInterface;

final readonly class FinalizeOrderCancellationAction
{
    public function __construct(
        private OrderRepositoryInterface $orderRepository,
        private EventBusInterface $eventBus,
    ) {
    }

    public function __invoke(Order $order): Order
    {
        $order->finalizeCancellation();
        $this->orderRepository->save($order);

        $this->eventBus->dispatch(new OrderCancellationFinalizedEvent(
            $order,
            new \DateTimeImmutable('now', new \DateTimeZone('UTC')),
        ));

        return $order;
    }
}
