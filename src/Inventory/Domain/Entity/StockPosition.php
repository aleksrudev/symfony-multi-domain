<?php

declare(strict_types=1);

namespace App\Inventory\Domain\Entity;

use App\Inventory\Domain\Exception\StockOnHandBelowReservedException;
use App\Inventory\Domain\ValueObject\ProductId;
use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\Id;
use Doctrine\ORM\Mapping\Table;

#[Entity]
#[Table(name: 'stock_positions', schema: 'inventory')]
class StockPosition
{
    #[Id]
    #[Column(name: 'product_id', type: 'inventory_product_id', length: 64)]
    private ProductId $productId;

    #[Column(name: 'on_hand', type: 'integer')]
    private int $onHand;

    #[Column(name: 'reserved', type: 'integer', options: ['default' => 0])]
    private int $reserved;

    #[Column(name: 'created_at', type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    #[Column(name: 'updated_at', type: 'datetime_immutable')]
    private \DateTimeImmutable $updatedAt;

    public function __construct(
        ProductId $productId,
        int $onHand,
        int $reserved = 0,
        ?\DateTimeImmutable $createdAt = null,
        ?\DateTimeImmutable $updatedAt = null,
    ) {
        if ($onHand < 0) {
            throw new \InvalidArgumentException('On-hand stock must be non-negative.');
        }

        if ($reserved < 0) {
            throw new \InvalidArgumentException('Reserved stock must be non-negative.');
        }

        if ($onHand < $reserved) {
            throw new StockOnHandBelowReservedException($onHand, $reserved);
        }

        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $this->productId = $productId;
        $this->onHand = $onHand;
        $this->reserved = $reserved;
        $this->createdAt = $createdAt ?? $now;
        $this->updatedAt = $updatedAt ?? $now;
    }

    public static function create(ProductId $productId, int $onHand = 0, int $reserved = 0): self
    {
        return new self($productId, $onHand, $reserved);
    }

    public function productId(): ProductId
    {
        return $this->productId;
    }

    public function onHand(): int
    {
        return $this->onHand;
    }

    public function reserved(): int
    {
        return $this->reserved;
    }

    public function available(): int
    {
        return max(0, $this->onHand - $this->reserved);
    }

    public function createdAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function updatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function changeOnHand(int $newOnHand): void
    {
        if ($newOnHand < 0) {
            throw new \InvalidArgumentException('On-hand stock must be non-negative.');
        }

        if ($newOnHand < $this->reserved) {
            throw new StockOnHandBelowReservedException($newOnHand, $this->reserved);
        }

        $this->onHand = $newOnHand;
        $this->updatedAt = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }
}