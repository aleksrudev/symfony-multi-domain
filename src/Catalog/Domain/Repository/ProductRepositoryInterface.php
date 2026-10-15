<?php

declare(strict_types=1);

namespace App\Catalog\Domain\Repository;

use App\Catalog\Domain\Entity\Product;

interface ProductRepositoryInterface
{
    public function save(Product $product): void;

    public function findById(string $id): ?Product;

    public function findPublishedById(string $id): ?Product;

    /**
     * @return array{items: list<Product>, total: int}
     */
    public function findPublishedPaginated(int $page, int $limit): array;
}

