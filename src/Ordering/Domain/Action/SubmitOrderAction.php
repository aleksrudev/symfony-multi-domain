<?php

declare(strict_types=1);

namespace App\Ordering\Domain\Action;

use App\Ordering\Domain\Collection\ProductSnapshotCollection;
use App\Ordering\Domain\Entity\Order;
use App\Ordering\Domain\Repository\OrderRepositoryInterface;

final readonly class SubmitOrderAction
{
    public function __construct(
        private OrderRepositoryInterface $orderRepository,
    ) {
    }

    public function __invoke(Order $order, ProductSnapshotCollection $snapshots): Order
    {
        $order->submit($snapshots);
        $this->orderRepository->save($order);

        return $order;
    }
}

