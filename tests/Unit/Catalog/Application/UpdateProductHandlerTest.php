<?php

declare(strict_types=1);

namespace Tests\Unit\Catalog\Application;

use App\Catalog\Application\Command\UpdateProduct\UpdateProductCommand;
use App\Catalog\Application\Command\UpdateProduct\UpdateProductHandler;
use App\Catalog\Domain\Entity\Product;
use App\Catalog\Domain\Exception\ProductNotFoundException;
use App\Catalog\Domain\Repository\ProductRepositoryInterface;
use PHPUnit\Framework\TestCase;

final class UpdateProductHandlerTest extends TestCase
{
    public function testUpdatesPriceAndPublishedStatus(): void
    {
        $product = new Product(
            id: 'prod-1',
            name: 'Original Name',
            priceAmount: 100000,
            published: true,
        );

        $repository = $this->createMock(ProductRepositoryInterface::class);
        $repository->expects(self::once())
            ->method('findById')
            ->with('prod-1')
            ->willReturn($product);
        $repository->expects(self::once())
            ->method('save')
            ->with($product);

        $handler = new UpdateProductHandler($repository);
        $handler(new UpdateProductCommand(
            productId: 'prod-1',
            priceAmount: 150000,
            published: false,
        ));

        self::assertSame(150000, $product->priceAmount());
        self::assertFalse($product->isPublished());
    }

    public function testThrowsExceptionWhenProductNotFound(): void
    {
        $repository = $this->createMock(ProductRepositoryInterface::class);
        $repository->expects(self::once())
            ->method('findById')
            ->with('prod-missing')
            ->willReturn(null);

        $handler = new UpdateProductHandler($repository);

        $this->expectException(ProductNotFoundException::class);
        $handler(new UpdateProductCommand(
            productId: 'prod-missing',
            priceAmount: 200000,
        ));
    }
}

