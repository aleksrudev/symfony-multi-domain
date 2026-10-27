<?php

declare(strict_types=1);

namespace App\Ordering\Domain\Action;

use App\Ordering\Domain\Collection\OrderItemCollection;
use App\Ordering\Domain\DataProvider\OrderDataProviderInterface;
use App\Ordering\Domain\Entity\Order;
use App\Ordering\Domain\Repository\OrderRepositoryInterface;
use App\Ordering\Domain\ValueObject\BuyerId;
use App\Ordering\Domain\ValueObject\OrderId;

final readonly class ReplaceOrderItemsAction
{
    public function __construct(
        private OrderDataProviderInterface $orderDataProvider,
        private OrderRepositoryInterface $orderRepository,
    ) {
    }

    public function __invoke(OrderId $orderId, BuyerId $buyerId, OrderItemCollection $items): Order
    {
        $order = $this->orderDataProvider->findByIdAndBuyerId($orderId, $buyerId);
        $order->replaceItems($items);
        $this->orderRepository->save($order);

        return $order;
    }
}
