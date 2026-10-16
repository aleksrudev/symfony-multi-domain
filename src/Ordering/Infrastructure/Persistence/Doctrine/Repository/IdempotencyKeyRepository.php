<?php

declare(strict_types=1);

namespace App\Ordering\Infrastructure\Persistence\Doctrine\Repository;

use App\Ordering\Domain\Entity\IdempotencyKey;
use App\Ordering\Domain\Repository\IdempotencyKeyRepositoryInterface;
use Doctrine\ORM\EntityManagerInterface;

final readonly class IdempotencyKeyRepository implements IdempotencyKeyRepositoryInterface
{
    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function save(IdempotencyKey $idempotencyKey): void
    {
        $this->entityManager->persist($idempotencyKey);
    }

    public function remove(IdempotencyKey $idempotencyKey): void
    {
        $this->entityManager->remove($idempotencyKey);
    }
}
