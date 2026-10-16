<?php

declare(strict_types=1);

namespace App\Ordering\Application\Command\CreateDraft;

use App\Ordering\Domain\Action\CreateDraftAction;
use App\Ordering\Domain\DataProvider\IdempotencyKeyDataProviderInterface;
use App\Ordering\Domain\Entity\IdempotencyKey;
use App\Ordering\Domain\Enum\IdempotencyOperation;
use App\Ordering\Domain\Exception\IdempotencyKeyNotFoundException;
use App\Ordering\Domain\Repository\IdempotencyKeyRepositoryInterface;
use App\Ordering\Domain\ValueObject\BuyerId;

final readonly class CreateDraftHandler
{
    public function __construct(
        private CreateDraftAction $createDraftAction,
        private IdempotencyKeyDataProviderInterface $idempotencyKeyDataProvider,
        private IdempotencyKeyRepositoryInterface $idempotencyKeyRepository,
    ) {
    }

    public function __invoke(CreateDraftCommand $command): void
    {
        $buyerId = new BuyerId($command->buyerId);

        if (null !== $command->idempotencyKey && '' !== trim($command->idempotencyKey)) {
            try {
                $existing = $this->idempotencyKeyDataProvider->findByKeyAndBuyerId($command->idempotencyKey, $buyerId);
                $existing->assertMatches($command->requestHash);

                return;
            } catch (IdempotencyKeyNotFoundException) {
                $idempotencyKey = new IdempotencyKey(
                    key: $command->idempotencyKey,
                    buyerId: $buyerId,
                    operation: IdempotencyOperation::CREATE_DRAFT,
                    requestHash: $command->requestHash,
                );
                $this->idempotencyKeyRepository->save($idempotencyKey);
            }
        }

        ($this->createDraftAction)($buyerId);
    }
}
