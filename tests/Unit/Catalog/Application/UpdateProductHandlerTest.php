<?php

declare(strict_types=1);

namespace Tests\Unit\Catalog\Application;

use App\Catalog\Application\Command\UpdateProduct\UpdateProductCommand;
use App\Catalog\Application\Command\UpdateProduct\UpdateProductHandler;
use App\Catalog\Domain\Action\UpdateProductAction;
use App\Catalog\Domain\DataProvider\ProductDataProviderInterface;
use App\Catalog\Domain\Entity\Product;
use App\Catalog\Domain\Enum\Currency;
use App\Catalog\Domain\Repository\ProductRepositoryInterface;
use App\Catalog\Domain\ValueObject\ProductId;
use PHPUnit\Framework\TestCase;

final class UpdateProductHandlerTest extends TestCase
{
    public function testDelegatesToUpdateProductAction(): void
    {
        $product = new Product(
            id: new ProductId('prod-1'),
            name: 'Original Name',
            priceAmount: 100000,
            currency: Currency::RUB,
            published: true,
        );

        $dataProvider = $this->createMock(ProductDataProviderInterface::class);
        $dataProvider->expects(self::once())
            ->method('findById')
            ->with(self::callback(static fn (ProductId $id): bool => 'prod-1' === $id->value))
            ->willReturn($product);

        $repository = $this->createMock(ProductRepositoryInterface::class);
        $repository->expects(self::once())
            ->method('save')
            ->with($product);

        $action = new UpdateProductAction($dataProvider, $repository);
        $handler = new UpdateProductHandler($action);

        $handler(new UpdateProductCommand(
            productId: 'prod-1',
            priceAmount: 150000,
            published: false,
        ));

        self::assertSame(150000, $product->priceAmount());
        self::assertFalse($product->isPublished());
    }
}
