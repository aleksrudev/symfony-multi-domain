<?php

declare(strict_types=1);

namespace App\Catalog\Domain\Collection;

use App\Catalog\Domain\Entity\Product;

/**
 * @implements \IteratorAggregate<int, Product>
 */
final class ProductCollection implements \Countable, \IteratorAggregate
{
    /** @var list<Product> */
    private array $items = [];

    /**
     * @param iterable<Product> $items
     */
    public function __construct(iterable $items = [])
    {
        foreach ($items as $item) {
            $this->add($item);
        }
    }

    public function add(Product $product): void
    {
        $this->items[] = $product;
    }

    /**
     * @return list<Product>
     */
    public function all(): array
    {
        return $this->items;
    }

    public function count(): int
    {
        return count($this->items);
    }

    public function getIterator(): \Traversable
    {
        return new \ArrayIterator($this->items);
    }
}
