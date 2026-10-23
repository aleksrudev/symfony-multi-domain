<?php

declare(strict_types=1);

namespace App\Inventory\Application\Query\GetStockPosition;

use App\Inventory\Domain\DataProvider\StockPositionDataProviderInterface;
use App\Inventory\Domain\Exception\StockPositionNotFoundException;
use App\Inventory\Domain\ValueObject\ProductId;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler(bus: 'query.bus')]
final readonly class GetStockPositionHandler
{
    public function __construct(
        private StockPositionDataProviderInterface $dataProvider,
    ) {
    }

    public function __invoke(GetStockPositionQuery $query): GetStockPositionQueryResult
    {
        try {
            $productId = new ProductId($query->productId);
        } catch (\InvalidArgumentException) {
            throw new StockPositionNotFoundException($query->productId);
        }

        $position = $this->dataProvider->findByProductId($productId);

        return new GetStockPositionQueryResult(
            productId: $position->productId()->value,
            onHand: $position->onHand(),
            reserved: $position->reserved(),
            available: $position->available(),
            updatedAt: $position->updatedAt()->format(\DateTimeInterface::ATOM),
        );
    }
}
