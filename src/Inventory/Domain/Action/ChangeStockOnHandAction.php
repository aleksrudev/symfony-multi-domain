<?php

declare(strict_types=1);

namespace App\Inventory\Domain\Action;

use App\Inventory\Domain\DataProvider\StockPositionDataProviderInterface;
use App\Inventory\Domain\Entity\StockPosition;
use App\Inventory\Domain\Repository\StockPositionRepositoryInterface;
use App\Inventory\Domain\ValueObject\ProductId;

final readonly class ChangeStockOnHandAction
{
    public function __construct(
        private StockPositionDataProviderInterface $dataProvider,
        private StockPositionRepositoryInterface $repository,
    ) {
    }

    public function __invoke(ProductId $productId, int $newOnHand): StockPosition
    {
        $position = $this->dataProvider->findByProductId($productId, lock: true);
        $position->changeOnHand($newOnHand);
        $this->repository->save($position);

        return $position;
    }
}

