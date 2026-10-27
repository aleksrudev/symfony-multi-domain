<?php

declare(strict_types=1);

namespace App\Ordering\Application\Command\ReplaceOrderItems;

use App\Ordering\Domain\Action\ReplaceOrderItemsAction;
use App\Ordering\Domain\Collection\OrderItemCollection;
use App\Ordering\Domain\Entity\OrderItem;
use App\Ordering\Domain\Exception\OrderNotFoundException;
use App\Ordering\Domain\ValueObject\BuyerId;
use App\Ordering\Domain\ValueObject\OrderId;
use App\Ordering\Domain\ValueObject\ProductId;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler(bus: 'command.bus')]
final readonly class ReplaceOrderItemsHandler
{
    public function __construct(
        private ReplaceOrderItemsAction $replaceOrderItemsAction,
    ) {
    }

    public function __invoke(ReplaceOrderItemsCommand $command): void
    {
        try {
            $orderId = new OrderId($command->orderId);
        } catch (\InvalidArgumentException) {
            throw new OrderNotFoundException($command->orderId);
        }

        $buyerId = new BuyerId($command->buyerId);

        $domainItems = [];
        foreach ($command->items as $item) {
            $domainItems[] = new OrderItem(
                productId: new ProductId($item->productId),
                quantity: $item->quantity,
            );
        }

        ($this->replaceOrderItemsAction)(
            $orderId,
            $buyerId,
            new OrderItemCollection($domainItems),
        );
    }
}
