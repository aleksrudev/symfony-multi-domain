<?php

declare(strict_types=1);

namespace App\Inventory\Infrastructure\Persistence\Doctrine\Repository;

use App\Inventory\Domain\Entity\StockPosition;
use App\Inventory\Domain\Repository\StockPositionRepositoryInterface;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

#[AsAlias(StockPositionRepositoryInterface::class)]
final readonly class StockPositionRepository implements StockPositionRepositoryInterface
{
    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function save(StockPosition $position): void
    {
        $this->entityManager->persist($position);
    }

    public function remove(StockPosition $position): void
    {
        $this->entityManager->remove($position);
    }
}
