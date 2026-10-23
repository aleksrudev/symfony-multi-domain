<?php

declare(strict_types=1);

namespace App\Inventory\Infrastructure\Persistence\Doctrine\DataProvider;

use App\Inventory\Domain\Collection\StockPositionCollection;
use App\Inventory\Domain\DataProvider\StockPositionDataProviderInterface;
use App\Inventory\Domain\Entity\StockPosition;
use App\Inventory\Domain\Exception\StockPositionNotFoundException;
use App\Inventory\Domain\ValueObject\ProductId;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

#[AsAlias(StockPositionDataProviderInterface::class)]
final readonly class StockPositionDataProvider implements StockPositionDataProviderInterface
{
    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function findByProductId(ProductId $productId, bool $lock = false): StockPosition
    {
        $position = $this->entityManager->find(
            StockPosition::class,
            $productId,
            $lock ? LockMode::PESSIMISTIC_WRITE : null,
        );

        if (null === $position) {
            throw new StockPositionNotFoundException($productId->value);
        }

        return $position;
    }

    public function findAll(): StockPositionCollection
    {
        /** @var list<StockPosition> $items */
        $items = $this->entityManager->getRepository(StockPosition::class)->findAll();

        return new StockPositionCollection($items);
    }
}
