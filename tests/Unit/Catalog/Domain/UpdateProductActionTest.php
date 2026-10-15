<?php

declare(strict_types=1);

namespace Tests\Unit\Catalog\Domain;

use App\Catalog\Domain\Action\UpdateProductAction;
use App\Catalog\Domain\Collection\ProductPaginatedCollection;
use App\Catalog\Domain\DataProvider\ProductDataProviderInterface;
use App\Catalog\Domain\Entity\Product;
use App\Catalog\Domain\Enum\Currency;
use App\Catalog\Domain\Exception\InvalidProductPriceException;
use App\Catalog\Domain\Exception\ProductNotFoundException;
use App\Catalog\Domain\Repository\ProductRepositoryInterface;
use App\Catalog\Domain\ValueObject\ProductId;
use PHPUnit\Framework\TestCase;

final class UpdateProductActionTest extends TestCase
{
    public function testUpdatesPriceAndPublicationStatus(): void
    {
        $product = new Product(
            id: new ProductId('prod-1'),
            name: 'Original',
            priceAmount: 1000,
            currency: Currency::RUB,
            published: true,
        );

        $dataProvider = new class($product) implements ProductDataProviderInterface {
            public function __construct(private readonly Product $product)
            {
            }

            public function findById(ProductId $id): Product
            {
                if ($id->equals($this->product->id())) {
                    return $this->product;
                }
                throw new ProductNotFoundException($id);
            }

            public function findPublishedById(ProductId $id): Product
            {
                throw new \LogicException('Not used');
            }

            public function findPublishedPaginated(int $offset, int $limit): ProductPaginatedCollection
            {
                throw new \LogicException('Not used');
            }
        };

        $saved = null;
        $repository = new class($saved) implements ProductRepositoryInterface {
            public function __construct(public ?Product &$saved)
            {
            }

            public function save(Product $product): void
            {
                $this->saved = $product;
            }

            public function remove(Product $product): void
            {
            }
        };

        $action = new UpdateProductAction($dataProvider, $repository);
        $result = $action(new ProductId('prod-1'), 2500, false);

        self::assertSame(2500, $result->priceAmount());
        self::assertFalse($result->isPublished());
        self::assertSame($result, $saved);
    }

    public function testRejectsNegativePrice(): void
    {
        $product = new Product(
            id: new ProductId('prod-1'),
            name: 'Original',
            priceAmount: 1000,
            currency: Currency::RUB,
            published: true,
        );

        $dataProvider = new class($product) implements ProductDataProviderInterface {
            public function __construct(private readonly Product $product)
            {
            }

            public function findById(ProductId $id): Product
            {
                return $this->product;
            }

            public function findPublishedById(ProductId $id): Product
            {
                throw new \LogicException('Not used');
            }

            public function findPublishedPaginated(int $offset, int $limit): ProductPaginatedCollection
            {
                throw new \LogicException('Not used');
            }
        };

        $repository = $this->createMock(ProductRepositoryInterface::class);
        $repository->expects(self::never())->method('save');

        $action = new UpdateProductAction($dataProvider, $repository);

        $this->expectException(InvalidProductPriceException::class);
        $action(new ProductId('prod-1'), -50, null);
    }
}

