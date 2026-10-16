<?php

declare(strict_types=1);

namespace App\Ordering\Infrastructure\Persistence\Doctrine\DataProvider;

use App\Ordering\Domain\DataProvider\IdempotencyKeyDataProviderInterface;
use App\Ordering\Domain\Entity\IdempotencyKey;
use App\Ordering\Domain\Exception\IdempotencyKeyNotFoundException;
use App\Ordering\Domain\ValueObject\BuyerId;
use Doctrine\ORM\EntityManagerInterface;

final readonly class IdempotencyKeyDataProvider implements IdempotencyKeyDataProviderInterface
{
    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function findByKeyAndBuyerId(string $key, BuyerId $buyerId): IdempotencyKey
    {
        $idempotencyKey = $this->entityManager->find(IdempotencyKey::class, [
            'key' => $key,
            'buyerId' => (string) $buyerId,
        ]);

        if (null === $idempotencyKey) {
            throw new IdempotencyKeyNotFoundException($key);
        }

        return $idempotencyKey;
    }
}

