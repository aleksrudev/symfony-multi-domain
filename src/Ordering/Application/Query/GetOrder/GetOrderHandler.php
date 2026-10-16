<?php

declare(strict_types=1);

namespace App\Ordering\Application\Query\GetOrder;

use App\Ordering\Domain\DataProvider\OrderDataProviderInterface;
use App\Ordering\Domain\ValueObject\BuyerId;
use App\Ordering\Domain\ValueObject\OrderId;

final readonly class GetOrderHandler
{
    public function __construct(
        private OrderDataProviderInterface $orderDataProvider,
    ) {
    }

    public function __invoke(GetOrderQuery $query): GetOrderQueryResult
    {
        $order = $this->orderDataProvider->findByIdAndBuyerId(
            new OrderId($query->orderId),
            new BuyerId($query->buyerId),
        );

        $items = [];
        foreach ($order->items() as $item) {
            $items[] = [
                'productId' => $item->productId()->value,
                'quantity' => $item->quantity(),
            ];
        }

        return new GetOrderQueryResult(
            id: $order->id()->value,
            buyerId: $order->buyerId()->value,
            status: $order->status()->value,
            items: $items,
            createdAt: $order->createdAt()->format(\DateTimeInterface::ATOM),
            updatedAt: $order->updatedAt()->format(\DateTimeInterface::ATOM),
        );
    }
}
