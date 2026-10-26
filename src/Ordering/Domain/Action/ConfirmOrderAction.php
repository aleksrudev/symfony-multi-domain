<?php

declare(strict_types=1);

namespace App\Ordering\Domain\Action;

use App\Ordering\Domain\Entity\Order;
use App\Ordering\Domain\Repository\OrderRepositoryInterface;

final readonly class ConfirmOrderAction
{
    public function __construct(
        private OrderRepositoryInterface $orderRepository,
    ) {
    }

    public function __invoke(Order $order): Order
    {
        $order->confirm();
        $this->orderRepository->save($order);

        return $order;
    }
}
