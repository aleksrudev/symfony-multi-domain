<?php

declare(strict_types=1);

namespace Tests\Integration\Ordering;

use App\Catalog\Infrastructure\Persistence\Doctrine\Fixtures\CatalogFixtures;
use App\Ordering\Application\Command\SubmitOrder\Provider\ProductOfferProviderInterface;
use App\Ordering\Infrastructure\Integration\Catalog\CatalogOfferProvider;
use Tests\Integration\IntegrationTestCase;

final class CatalogIntegrationTest extends IntegrationTestCase
{
    private ProductOfferProviderInterface $offerProvider;

    protected function setUp(): void
    {
        parent::setUp();
        $this->offerProvider = self::getContainer()->get(ProductOfferProviderInterface::class);

        $fixtures = new CatalogFixtures();
        $fixtures->load($this->em);
    }

    public function testOfferProviderIsResolvedAsCatalogOfferProvider(): void
    {
        self::assertInstanceOf(CatalogOfferProvider::class, $this->offerProvider);
    }

    public function testGetOfferReturnsSnapshotForPublishedProduct(): void
    {
        $offer = $this->offerProvider->getOffer(CatalogFixtures::PRODUCT_KEYBOARD);

        self::assertNotNull($offer);
        self::assertSame(CatalogFixtures::PRODUCT_KEYBOARD, $offer->productId);
        self::assertSame('Механическая клавиатура', $offer->name);
        self::assertSame(799000, $offer->priceAmount);
        self::assertSame('RUB', $offer->currency);
    }

    public function testGetOfferReturnsNullForUnpublishedProduct(): void
    {
        $offer = $this->offerProvider->getOffer(CatalogFixtures::PRODUCT_HEADPHONES);

        self::assertNull($offer);
    }

    public function testGetOfferReturnsNullForNonExistentProduct(): void
    {
        $offer = $this->offerProvider->getOffer('prod-does-not-exist');

        self::assertNull($offer);
    }
}
