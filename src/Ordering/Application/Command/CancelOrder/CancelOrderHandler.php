<?php

declare(strict_types=1);

namespace App\Ordering\Application\Command\CancelOrder;

use App\Ordering\Domain\Action\CancelOrderAction;
use App\Ordering\Domain\DataProvider\IdempotencyKeyDataProviderInterface;
use App\Ordering\Domain\DataProvider\OrderDataProviderInterface;
use App\Ordering\Domain\Entity\IdempotencyKey;
use App\Ordering\Domain\Enum\IdempotencyOperation;
use App\Ordering\Domain\Exception\IdempotencyKeyNotFoundException;
use App\Ordering\Domain\Exception\OrderNotFoundException;
use App\Ordering\Domain\Repository\IdempotencyKeyRepositoryInterface;
use App\Ordering\Domain\ValueObject\BuyerId;
use App\Ordering\Domain\ValueObject\OrderId;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler(bus: 'command.bus')]
final readonly class CancelOrderHandler
{
    public function __construct(
        private OrderDataProviderInterface $orderDataProvider,
        private CancelOrderAction $cancelOrderAction,
        private ?IdempotencyKeyDataProviderInterface $idempotencyKeyDataProvider = null,
        private ?IdempotencyKeyRepositoryInterface $idempotencyKeyRepository = null,
    ) {
    }

    public function __invoke(CancelOrderCommand $command): void
    {
        try {
            $orderId = new OrderId($command->orderId);
        } catch (\InvalidArgumentException) {
            throw new OrderNotFoundException($command->orderId);
        }

        $buyerId = null !== $command->buyerId ? new BuyerId($command->buyerId) : null;

        if (null !== $buyerId && null !== $command->idempotencyKey && '' !== trim($command->idempotencyKey) && null !== $this->idempotencyKeyDataProvider) {
            try {
                $existing = $this->idempotencyKeyDataProvider->findByKeyAndBuyerId($command->idempotencyKey, $buyerId);
                $existing->assertMatches($command->requestHash);

                return;
            } catch (IdempotencyKeyNotFoundException) {
                // Key does not exist yet; will be saved after successful execution
            }
        }

        $order = $this->orderDataProvider->findById($orderId, lock: true);

        if (null !== $buyerId && $order->buyerId()->value !== $buyerId->value) {
            throw new OrderNotFoundException($command->orderId);
        }

        ($this->cancelOrderAction)($order);

        if (null !== $buyerId && null !== $command->idempotencyKey && '' !== trim($command->idempotencyKey) && null !== $this->idempotencyKeyRepository) {
            $idempotencyKey = new IdempotencyKey(
                key: $command->idempotencyKey,
                buyerId: $buyerId,
                operation: IdempotencyOperation::CANCEL_ORDER,
                requestHash: $command->requestHash,
            );
            $this->idempotencyKeyRepository->save($idempotencyKey);
        }
    }
}
