<?php

declare(strict_types=1);

namespace Tests\Unit\Catalog\Domain;

use App\Catalog\Domain\Entity\Product;
use App\Catalog\Domain\Enum\Currency;
use App\Catalog\Domain\Exception\InvalidProductPriceException;
use App\Catalog\Domain\ValueObject\ProductId;
use PHPUnit\Framework\TestCase;

final class ProductTest extends TestCase
{
    public function testCanCreateProductWithValidData(): void
    {
        $id = new ProductId('prod-1');
        $product = new Product(
            id: $id,
            name: 'Keyboard',
            priceAmount: 500000,
            currency: Currency::RUB,
            published: true,
        );

        self::assertTrue($product->id()->equals($id));
        self::assertSame('Keyboard', $product->name());
        self::assertSame(500000, $product->priceAmount());
        self::assertSame(Currency::RUB, $product->currency());
        self::assertTrue($product->isPublished());
        self::assertInstanceOf(\DateTimeImmutable::class, $product->createdAt());
    }

    public function testThrowsExceptionOnZeroOrNegativePrice(): void
    {
        $this->expectException(InvalidProductPriceException::class);
        new Product(
            id: new ProductId('prod-1'),
            name: 'Keyboard',
            priceAmount: 0,
        );
    }

    public function testThrowsExceptionOnChangePriceZeroOrNegative(): void
    {
        $product = new Product(
            id: new ProductId('prod-1'),
            name: 'Keyboard',
            priceAmount: 500000,
        );

        $this->expectException(InvalidProductPriceException::class);
        $product->changePrice(-100);
    }

    public function testCanChangePrice(): void
    {
        $product = new Product(
            id: new ProductId('prod-1'),
            name: 'Keyboard',
            priceAmount: 500000,
        );

        $product->changePrice(600000);
        self::assertSame(600000, $product->priceAmount());
    }

    public function testCanPublishAndUnpublish(): void
    {
        $product = new Product(
            id: new ProductId('prod-1'),
            name: 'Keyboard',
            priceAmount: 500000,
            published: true,
        );

        $product->unpublish();
        self::assertFalse($product->isPublished());

        $product->publish();
        self::assertTrue($product->isPublished());
    }

    public function testCanRename(): void
    {
        $product = new Product(
            id: new ProductId('prod-1'),
            name: 'Old Name',
            priceAmount: 500000,
        );

        $product->rename('New Name');
        self::assertSame('New Name', $product->name());
    }
}
