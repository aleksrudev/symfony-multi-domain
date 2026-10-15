<?php

declare(strict_types=1);

namespace Tests\Integration\Catalog;

use App\Catalog\Domain\Entity\Product;
use App\Catalog\Domain\Enum\Currency;
use App\Catalog\Domain\Repository\ProductRepositoryInterface;
use App\Catalog\Domain\ValueObject\ProductId;
use App\Catalog\Presentation\Contracts\CatalogContractInterface;
use App\Catalog\Presentation\Contracts\Dto\Offer;
use Tests\Integration\IntegrationTestCase;

final class CatalogContractTest extends IntegrationTestCase
{
    private CatalogContractInterface $catalogContract;
    private ProductRepositoryInterface $repository;

    protected function setUp(): void
    {
        parent::setUp();
        $this->catalogContract = self::getContainer()->get(CatalogContractInterface::class);
        $this->repository = self::getContainer()->get(ProductRepositoryInterface::class);
    }

    public function testGetOfferReturnsOfferForPublishedProduct(): void
    {
        $product = new Product(
            id: new ProductId('prod-contract-1'),
            name: 'Test Contract Product',
            priceAmount: 199900,
            currency: Currency::RUB,
            published: true,
        );
        $this->repository->save($product);
        $this->em->flush();

        $offer = $this->catalogContract->getOffer('prod-contract-1');

        self::assertInstanceOf(Offer::class, $offer);
        self::assertSame('prod-contract-1', $offer->productId);
        self::assertSame('Test Contract Product', $offer->name);
        self::assertSame(199900, $offer->priceAmount);
        self::assertSame('RUB', $offer->currency);
    }

    public function testGetOfferReturnsNullWhenProductIsUnpublished(): void
    {
        $product = new Product(
            id: new ProductId('prod-contract-hidden'),
            name: 'Hidden Product',
            priceAmount: 250000,
            currency: Currency::RUB,
            published: false,
        );
        $this->repository->save($product);
        $this->em->flush();

        $offer = $this->catalogContract->getOffer('prod-contract-hidden');

        self::assertNull($offer);
    }

    public function testGetOfferReturnsNullForNonExistentProduct(): void
    {
        $offer = $this->catalogContract->getOffer('prod-does-not-exist');

        self::assertNull($offer);
    }
}
