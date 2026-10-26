<?php

declare(strict_types=1);

namespace App\Ordering\Domain\Action;

use App\Ordering\Domain\Entity\Order;
use App\Ordering\Domain\Repository\OrderRepositoryInterface;

final readonly class RejectOrderAction
{
    public function __construct(
        private OrderRepositoryInterface $orderRepository,
    ) {
    }

    public function __invoke(Order $order, string $reason): Order
    {
        $order->reject($reason);
        $this->orderRepository->save($order);

        return $order;
    }
}
