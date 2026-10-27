<?php

declare(strict_types=1);

namespace App\Catalog\Infrastructure\Persistence\Doctrine\Fixtures;

use App\Catalog\Domain\Entity\Product;
use App\Catalog\Domain\Enum\Currency;
use App\Catalog\Domain\ValueObject\ProductId;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Persistence\ObjectManager;
use Symfony\Component\DependencyInjection\Attribute\When;

#[When('dev')]
#[When('test')]
final class CatalogFixtures extends Fixture
{
    public const string PRODUCT_KEYBOARD = 'prod-keyboard';
    public const string PRODUCT_MOUSE = 'prod-mouse';
    public const string PRODUCT_MONITOR = 'prod-monitor';
    public const string PRODUCT_HEADPHONES = 'prod-headphones';

    public function load(ObjectManager $manager): void
    {
        $existing = $manager->find(Product::class, new ProductId(self::PRODUCT_KEYBOARD));
        if (null !== $existing) {
            return;
        }

        $products = [
            new Product(
                id: new ProductId(self::PRODUCT_KEYBOARD),
                name: 'Механическая клавиатура',
                priceAmount: 799000,
                currency: Currency::RUB,
                published: true,
            ),
            new Product(
                id: new ProductId(self::PRODUCT_MOUSE),
                name: 'Беспроводная мышь',
                priceAmount: 349000,
                currency: Currency::RUB,
                published: true,
            ),
            new Product(
                id: new ProductId(self::PRODUCT_MONITOR),
                name: 'Игровой монитор 27"',
                priceAmount: 2499000,
                currency: Currency::RUB,
                published: true,
            ),
            new Product(
                id: new ProductId(self::PRODUCT_HEADPHONES),
                name: 'Беспроводные наушники',
                priceAmount: 599000,
                currency: Currency::RUB,
                published: false,
            ),
        ];

        foreach ($products as $product) {
            $manager->persist($product);
        }

        $manager->flush();
    }
}
