<?php

declare(strict_types=1);

namespace Tests\Unit\Catalog\Presentation;

use App\Catalog\Application\Query\GetProduct\GetProductQuery;
use App\Catalog\Application\Query\GetProduct\GetProductQueryResult;
use App\Catalog\Domain\Exception\ProductNotFoundException;
use App\Catalog\Presentation\Adapter\CatalogContractAdapter;
use App\Catalog\Presentation\Contracts\Dto\Offer;
use App\Shared\Application\Bus\Query\QueryBusInterface;
use PHPUnit\Framework\TestCase;

final class CatalogContractAdapterTest extends TestCase
{
    public function testReturnsOfferWhenPublishedProductFound(): void
    {
        $queryBus = $this->createMock(QueryBusInterface::class);
        $queryBus->expects(self::once())
            ->method('dispatch')
            ->with(self::callback(static function (GetProductQuery $query): bool {
                return 'prod-1' === $query->productId && true === $query->onlyPublished;
            }))
            ->willReturn(new GetProductQueryResult(
                productId: 'prod-1',
                name: 'Mechanical Keyboard',
                priceAmount: 799000,
                currency: 'RUB',
                published: true,
            ));

        $adapter = new CatalogContractAdapter($queryBus);
        $offer = $adapter->getOffer('prod-1');

        self::assertInstanceOf(Offer::class, $offer);
        self::assertSame('prod-1', $offer->productId);
        self::assertSame('Mechanical Keyboard', $offer->name);
        self::assertSame(799000, $offer->priceAmount);
        self::assertSame('RUB', $offer->currency);
    }

    public function testReturnsNullWhenProductNotFound(): void
    {
        $queryBus = $this->createMock(QueryBusInterface::class);
        $queryBus->expects(self::once())
            ->method('dispatch')
            ->willThrowException(new ProductNotFoundException('prod-hidden'));

        $adapter = new CatalogContractAdapter($queryBus);
        $offer = $adapter->getOffer('prod-hidden');

        self::assertNull($offer);
    }
}
