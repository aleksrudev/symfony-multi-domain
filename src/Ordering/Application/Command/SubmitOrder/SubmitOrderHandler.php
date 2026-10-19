<?php

declare(strict_types=1);

namespace App\Ordering\Application\Command\SubmitOrder;

use App\Ordering\Application\Command\SubmitOrder\Provider\ProductOfferProviderInterface;
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

final readonly class SubmitOrderHandler
{
    public function __construct(
        private OrderDataProviderInterface $orderDataProvider,
        private ProductOfferProviderInterface $productOfferProvider,
        private SubmitOrderAction $submitOrderAction,
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
            $offer = $this->productOfferProvider->getOffer($item->productId()->value);
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

        ($this->submitOrderAction)($order, new ProductSnapshotCollection($snapshots));

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
