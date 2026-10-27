<?php

declare(strict_types=1);

namespace App\Ordering\Domain\Action;

use App\Ordering\Domain\DataProvider\OrderDataProviderInterface;
use App\Ordering\Domain\Entity\Order;
use App\Ordering\Domain\Exception\OrderNotFoundException;
use App\Ordering\Domain\Repository\OrderRepositoryInterface;
use App\Ordering\Domain\ValueObject\BuyerId;
use App\Ordering\Domain\ValueObject\OrderId;

final readonly class CreateDraftAction
{
    public function __construct(
        private OrderDataProviderInterface $orderDataProvider,
        private OrderRepositoryInterface $orderRepository,
    ) {
    }

    public function __invoke(BuyerId $buyerId, ?OrderId $orderId = null): Order
    {
        try {
            return $this->orderDataProvider->findDraftByBuyerId($buyerId);
        } catch (OrderNotFoundException) {
            $order = Order::createDraft($orderId ?? new OrderId(), $buyerId);
            $this->orderRepository->save($order);

            return $order;
        }
    }
}
