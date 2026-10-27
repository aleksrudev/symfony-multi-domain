<?php

declare(strict_types=1);

namespace App\Ordering\Application\Query\GetOrder;

use App\Ordering\Application\Query\GetOrder\Result\GetOrderQueryResult;
use App\Ordering\Application\Query\GetOrder\Result\OrderItemQueryResult;
use App\Ordering\Domain\DataProvider\OrderDataProviderInterface;
use App\Ordering\Domain\Exception\OrderNotFoundException;
use App\Ordering\Domain\ValueObject\BuyerId;
use App\Ordering\Domain\ValueObject\OrderId;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler(bus: 'query.bus')]
final readonly class GetOrderHandler
{
    public function __construct(
        private OrderDataProviderInterface $orderDataProvider,
    ) {
    }

    public function __invoke(GetOrderQuery $query): GetOrderQueryResult
    {
        try {
            $orderId = new OrderId($query->orderId);
        } catch (\InvalidArgumentException) {
            throw new OrderNotFoundException($query->orderId);
        }

        $order = $this->orderDataProvider->findByIdAndBuyerId(
            $orderId,
            new BuyerId($query->buyerId),
        );

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

        return new GetOrderQueryResult(
            id: $order->id()->value,
            buyerId: $order->buyerId()->value,
            status: $order->status()->value,
            items: $items,
            createdAt: $order->createdAt()->format(\DateTimeInterface::ATOM),
            updatedAt: $order->updatedAt()->format(\DateTimeInterface::ATOM),
            totalAmount: $order->totalAmount(),
            currency: $order->currency()?->value,
            rejectionReason: $order->rejectionReason(),
        );
    }
}
