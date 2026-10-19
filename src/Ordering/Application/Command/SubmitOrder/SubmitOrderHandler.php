<?php

declare(strict_types=1);

namespace App\Ordering\Application\Command\SubmitOrder;

use App\Ordering\Domain\Action\SubmitOrderAction;
use App\Ordering\Domain\Collection\ProductSnapshotCollection;
use App\Ordering\Domain\DataProvider\IdempotencyKeyDataProviderInterface;
use App\Ordering\Domain\DataProvider\OrderDataProviderInterface;
use App\Ordering\Domain\Entity\IdempotencyKey;
use App\Ordering\Domain\Enum\Currency;
use App\Ordering\Domain\Enum\IdempotencyOperation;
use App\Ordering\Domain\Exception\IdempotencyKeyNotFoundException;
use App\Ordering\Domain\Exception\OrderNotFoundException;
use App\Ordering\Domain\Exception\ProductNotAvailableException;
use App\Ordering\Domain\Repository\IdempotencyKeyRepositoryInterface;
use App\Ordering\Domain\ValueObject\BuyerId;
use App\Ordering\Domain\ValueObject\OrderId;
use App\Ordering\Domain\ValueObject\ProductSnapshot;
use App\Shared\Application\Bus\Outbox\OutboxBusInterface;

final readonly class SubmitOrderHandler
{
    public function __construct(
        private OrderDataProviderInterface $orderDataProvider,
        private OfferProviderInterface $offerProvider,
        private SubmitOrderAction $submitOrderAction,
        private OutboxBusInterface $outboxBus,
        private IdempotencyKeyDataProviderInterface $idempotencyKeyDataProvider,
        private IdempotencyKeyRepositoryInterface $idempotencyKeyRepository,
    ) {
    }

    public function __invoke(SubmitOrderCommand $command): void
    {
        $buyerId = new BuyerId($command->buyerId);

        try {
            $orderId = new OrderId($command->orderId);
        } catch (\InvalidArgumentException) {
            throw new OrderNotFoundException($command->orderId);
        }

        if (null !== $command->idempotencyKey && '' !== trim($command->idempotencyKey)) {
            try {
                $existing = $this->idempotencyKeyDataProvider->findByKeyAndBuyerId($command->idempotencyKey, $buyerId);
                $existing->assertMatches($command->requestHash);

                return;
            } catch (IdempotencyKeyNotFoundException) {
                // Key does not exist yet; will be saved after successful execution
            }
        }

        $order = $this->orderDataProvider->findByIdAndBuyerId($orderId, $buyerId);

        $snapshots = [];
        foreach ($order->items() as $item) {
            $offer = $this->offerProvider->getOffer($item->productId()->value);
            if (null === $offer) {
                throw new ProductNotAvailableException($item->productId());
            }

            $snapshots[] = new ProductSnapshot(
                productId: $item->productId(),
                name: $offer->name,
                priceAmount: $offer->priceAmount,
                currency: Currency::from($offer->currency),
            );
        }

        $submittedOrder = ($this->submitOrderAction)($order, new ProductSnapshotCollection($snapshots));

        $itemsPayload = [];
        foreach ($submittedOrder->items() as $item) {
            $itemsPayload[] = [
                'productId' => $item->productId()->value,
                'quantity' => $item->quantity(),
            ];
        }

        $outboxMessage = new OrderSubmittedOutboxMessage(
            orderId: $submittedOrder->id()->value,
            buyerId: $submittedOrder->buyerId()->value,
            items: $itemsPayload,
            totalAmount: $submittedOrder->totalAmount() ?? 0,
            currency: ($submittedOrder->currency() ?? Currency::RUB)->value,
            occurredAt: $submittedOrder->updatedAt()->format(\DateTimeInterface::ATOM),
        );
        $this->outboxBus->publish($outboxMessage);

        if (null !== $command->idempotencyKey && '' !== trim($command->idempotencyKey)) {
            $idempotencyKey = new IdempotencyKey(
                key: $command->idempotencyKey,
                buyerId: $buyerId,
                operation: IdempotencyOperation::SUBMIT_ORDER,
                requestHash: $command->requestHash,
            );
            $this->idempotencyKeyRepository->save($idempotencyKey);
        }
    }
}
