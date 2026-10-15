<?php

declare(strict_types=1);

namespace Tests\Unit\Catalog\Domain;

use App\Catalog\Domain\Collection\ProductCollection;
use App\Catalog\Domain\Collection\ProductPaginatedCollection;
use App\Catalog\Domain\Entity\Product;
use App\Catalog\Domain\Enum\Currency;
use App\Catalog\Domain\ValueObject\ProductId;
use PHPUnit\Framework\TestCase;

final class ProductCollectionTest extends TestCase
{
    public function testCollectionIterationAndCount(): void
    {
        $product1 = new Product(new ProductId('p-1'), 'P1', 100, Currency::RUB);
        $product2 = new Product(new ProductId('p-2'), 'P2', 200, Currency::RUB);

        $collection = new ProductCollection([$product1, $product2]);

        self::assertCount(2, $collection);
        self::assertSame([$product1, $product2], $collection->all());

        $iterated = [];
        foreach ($collection as $item) {
            $iterated[] = $item;
        }
        self::assertSame([$product1, $product2], $iterated);
    }

    public function testPaginatedCollectionStructure(): void
    {
        $product1 = new Product(new ProductId('p-1'), 'P1', 100, Currency::RUB);
        $collection = new ProductCollection([$product1]);

        $paginated = new ProductPaginatedCollection($collection, 42);

        self::assertSame($collection, $paginated->items);
        self::assertSame(42, $paginated->count);
    }
}

