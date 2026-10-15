<?php

declare(strict_types=1);

namespace App\Catalog\Application\Command\InitDemo;

use App\Catalog\Domain\Entity\Product;
use App\Catalog\Domain\Repository\ProductRepositoryInterface;

final readonly class InitDemoCatalogHandler
{
    /**
     * @var list<array{id: string, name: string, price: int, published: bool}>
     */
    private const DEMO_PRODUCTS = [
        [
            'id' => 'prod-keyboard',
            'name' => 'Механическая клавиатура',
            'price' => 899000,
            'published' => true,
        ],
        [
            'id' => 'prod-mouse',
            'name' => 'Беспроводная мышь',
            'price' => 450000,
            'published' => true,
        ],
        [
            'id' => 'prod-monitor',
            'name' => 'Игровой монитор 27"',
            'price' => 2999000,
            'published' => true,
        ],
        [
            'id' => 'prod-headphones',
            'name' => 'Наушники с шумоподавлением',
            'price' => 1299000,
            'published' => false,
        ],
    ];

    public function __construct(
        private ProductRepositoryInterface $repository,
    ) {
    }

    public function __invoke(InitDemoCatalogCommand $command): void
    {
        foreach (self::DEMO_PRODUCTS as $item) {
            $existing = $this->repository->findById($item['id']);
            if (null === $existing) {
                $product = new Product(
                    id: $item['id'],
                    name: $item['name'],
                    priceAmount: $item['price'],
                    currency: 'RUB',
                    published: $item['published'],
                );
                $this->repository->save($product);
            }
        }
    }
}

