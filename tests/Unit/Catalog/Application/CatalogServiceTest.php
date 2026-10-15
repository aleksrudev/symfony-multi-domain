<?php

declare(strict_types=1);

namespace Tests\Unit\Catalog\Application;

use App\Catalog\Application\Service\CatalogService;
use App\Catalog\Contracts\Offer;
use App\Catalog\Domain\Entity\Product;
use App\Catalog\Domain\Repository\ProductRepositoryInterface;
use PHPUnit\Framework\TestCase;

final class CatalogServiceTest extends TestCase
{
    public function testReturnsOfferForPublishedProduct(): void
    {
        $product = new Product(
            id: 'prod-100',
            name: 'Gaming Mouse',
            priceAmount: 450000,
            currency: 'RUB',
            published: true,
        );

        $repository = $this->createMock(ProductRepositoryInterface::class);
        $repository->expects(self::once())
            ->method('findPublishedById')
            ->with('prod-100')
            ->willReturn($product);

        $service = new CatalogService($repository);
        $offer = $service->getOffer('prod-100');

        self::assertInstanceOf(Offer::class, $offer);
        self::assertSame('prod-100', $offer->productId);
        self::assertSame('Gaming Mouse', $offer->name);
        self::assertSame(450000, $offer->priceAmount);
        self::assertSame('RUB', $offer->currency);
    }

    public function testReturnsNullForUnpublishedOrMissingProduct(): void
    {
        $repository = $this->createMock(ProductRepositoryInterface::class);
        $repository->expects(self::once())
            ->method('findPublishedById')
            ->with('missing-or-hidden')
            ->willReturn(null);

        $service = new CatalogService($repository);
        $offer = $service->getOffer('missing-or-hidden');

        self::assertNull($offer);
    }
}

