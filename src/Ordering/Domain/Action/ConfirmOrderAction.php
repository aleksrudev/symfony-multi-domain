<?php

declare(strict_types=1);

namespace App\Ordering\Domain\Action;

use App\Ordering\Domain\Entity\Order;
use App\Ordering\Domain\Event\OrderConfirmedEvent;
use App\Ordering\Domain\Repository\OrderRepositoryInterface;
use App\Shared\Domain\Event\EventBusInterface;

final readonly class ConfirmOrderAction
{
    public function __construct(
        private OrderRepositoryInterface $orderRepository,
        private EventBusInterface $eventBus,
    ) {
    }

    public function __invoke(Order $order): Order
    {
        $order->confirm();
        $this->orderRepository->save($order);

        $this->eventBus->dispatch(new OrderConfirmedEvent(
            $order,
            new \DateTimeImmutable('now', new \DateTimeZone('UTC')),
        ));

        return $order;
    }
}
