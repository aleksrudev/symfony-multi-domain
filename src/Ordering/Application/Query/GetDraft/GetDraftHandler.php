<?php

declare(strict_types=1);

namespace App\Ordering\Application\Query\GetDraft;

use App\Ordering\Application\Query\GetOrder\GetOrderQueryResult;
use App\Ordering\Domain\DataProvider\OrderDataProviderInterface;
use App\Ordering\Domain\ValueObject\BuyerId;

final readonly class GetDraftHandler
{
    public function __construct(
        private OrderDataProviderInterface $orderDataProvider,
    ) {
    }

    public function __invoke(GetDraftQuery $query): GetOrderQueryResult
    {
        $order = $this->orderDataProvider->findDraftByBuyerId(new BuyerId($query->buyerId));

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
