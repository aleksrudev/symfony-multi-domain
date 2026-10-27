<?php

declare(strict_types=1);

namespace App\Inventory\Infrastructure\Persistence\Doctrine\Fixtures;

use App\Inventory\Domain\Entity\StockPosition;
use App\Inventory\Domain\ValueObject\ProductId;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Persistence\ObjectManager;
use Symfony\Component\DependencyInjection\Attribute\When;

#[When('dev')]
#[When('test')]
final class InventoryFixtures extends Fixture
{
    public const string PRODUCT_KEYBOARD = 'prod-keyboard';
    public const string PRODUCT_MOUSE = 'prod-mouse';
    public const string PRODUCT_MONITOR = 'prod-monitor';
    public const string PRODUCT_HEADPHONES = 'prod-headphones';

    public function load(ObjectManager $manager): void
    {
        $positions = [
            self::PRODUCT_KEYBOARD => 10,
            self::PRODUCT_MOUSE => 20,
            self::PRODUCT_MONITOR => 5,
            self::PRODUCT_HEADPHONES => 0,
        ];

        foreach ($positions as $productId => $onHand) {
            $existing = $manager->find(StockPosition::class, new ProductId($productId));
            if ($existing instanceof StockPosition) {
                $existing->changeOnHand($onHand);
            } else {
                $manager->persist(new StockPosition(new ProductId($productId), $onHand));
            }
        }

        $manager->flush();
    }
}
