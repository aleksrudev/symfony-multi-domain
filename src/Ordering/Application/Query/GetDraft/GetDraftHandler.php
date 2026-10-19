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
            $items[] = $itemData;
        }

        return new GetOrderQueryResult(
            id: $order->id()->value,
            buyerId: $order->buyerId()->value,
            status: $order->status()->value,
            items: $items,
            createdAt: $order->createdAt()->format(\DateTimeInterface::ATOM),
            updatedAt: $order->updatedAt()->format(\DateTimeInterface::ATOM),
            totalAmount: $order->totalAmount(),
            currency: $order->currency()?->value,
        );
    }
}
