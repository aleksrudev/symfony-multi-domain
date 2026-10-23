<?php

declare(strict_types=1);

namespace App\Ordering\Application\Query\GetDraft;

use App\Ordering\Application\Query\GetOrder\OrderItemQueryResult;
use App\Ordering\Domain\DataProvider\OrderDataProviderInterface;
use App\Ordering\Domain\ValueObject\BuyerId;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler(bus: 'query.bus')]
final readonly class GetDraftHandler
{
    public function __construct(
        private OrderDataProviderInterface $orderDataProvider,
    ) {
    }

    public function __invoke(GetDraftQuery $query): GetDraftQueryResult
    {
        $order = $this->orderDataProvider->findDraftByBuyerId(new BuyerId($query->buyerId));

        $items = [];
        foreach ($order->items() as $item) {
            $items[] = new OrderItemQueryResult(
                productId: $item->productId()->value,
                quantity: $item->quantity(),
                productName: $item->productName(),
                priceAmount: $item->priceAmount(),
                currency: $item->currency()?->value,
            );
        }

        return new GetDraftQueryResult(
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
