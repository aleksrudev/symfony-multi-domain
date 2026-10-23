<?php

declare(strict_types=1);

namespace App\Catalog\Presentation\Adapter;

use App\Catalog\Application\Query\GetProduct\GetProductQuery;
use App\Catalog\Application\Query\GetProduct\GetProductQueryResult;
use App\Catalog\Domain\Exception\ProductNotFoundException;
use App\Catalog\Presentation\Contracts\CatalogContractInterface;
use App\Catalog\Presentation\Contracts\Dto\Offer;
use App\Shared\Application\Bus\Query\QueryBusInterface;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

#[AsAlias(CatalogContractInterface::class)]
final readonly class CatalogContractAdapter implements CatalogContractInterface
{
    public function __construct(
        private QueryBusInterface $queryBus,
    ) {
    }

    public function getOffer(string $productId): ?Offer
    {
        try {
            /** @var GetProductQueryResult $result */
            $result = $this->queryBus->dispatch(new GetProductQuery(
                productId: $productId,
                onlyPublished: true,
            ));

            return new Offer(
                productId: $result->productId,
                name: $result->name,
                priceAmount: $result->priceAmount,
                currency: $result->currency,
            );
        } catch (ProductNotFoundException) {
            return null;
        }
    }
}
