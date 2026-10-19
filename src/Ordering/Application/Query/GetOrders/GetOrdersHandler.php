<?php

declare(strict_types=1);

namespace App\Ordering\Application\Query\GetOrders;

use App\Ordering\Application\Query\GetOrder\GetOrderQueryResult;
use App\Ordering\Domain\DataProvider\OrderDataProviderInterface;
use App\Ordering\Domain\ValueObject\BuyerId;

final readonly class GetOrdersHandler
{
    public function __construct(
        private OrderDataProviderInterface $orderDataProvider,
    ) {
    }

    public function __invoke(GetOrdersQuery $query): GetOrdersQueryResult
    {
        $offset = ($query->page - 1) * $query->limit;
        $paginated = $this->orderDataProvider->findByBuyerIdPaginated(
            new BuyerId($query->buyerId),
            $offset,
            $query->limit,
        );

        $items = [];
        foreach ($paginated->items as $order) {
            $orderItems = [];
            foreach ($order->items() as $item) {
                $itemData = [
                    'productId' => $item->productId()->value,
                    'quantity' => $item->quantity(),
                ];
                if (null !== $item->productName()) {
                    $itemData['name'] = $item->productName();
                }
                if (null !== $item->priceAmount()) {
                    $itemData['price'] = $item->priceAmount();
                }
                if (null !== $item->currency()) {
                    $itemData['currency'] = $item->currency()->value;
                }
                $orderItems[] = $itemData;
            }

            $items[] = new GetOrderQueryResult(
                id: $order->id()->value,
                buyerId: $order->buyerId()->value,
                status: $order->status()->value,
                items: $orderItems,
                createdAt: $order->createdAt()->format(\DateTimeInterface::ATOM),
                updatedAt: $order->updatedAt()->format(\DateTimeInterface::ATOM),
                totalAmount: $order->totalAmount(),
                currency: $order->currency()?->value,
            );
        }

        return new GetOrdersQueryResult(
            items: $items,
            total: $paginated->count,
            page: $query->page,
            limit: $query->limit,
        );
    }
}
