<?php

declare(strict_types=1);

namespace App\Catalog\Infrastructure\Persistence\Doctrine\Repository;

use App\Catalog\Domain\Entity\Product;
use App\Catalog\Domain\Repository\ProductRepositoryInterface;
use Doctrine\ORM\EntityManagerInterface;

final readonly class ProductRepository implements ProductRepositoryInterface
{
    public function __construct(
        private EntityManagerInterface $em,
    ) {
    }

    public function save(Product $product): void
    {
        $this->em->persist($product);
    }

    public function remove(Product $product): void
    {
        $this->em->remove($product);
    }
}
