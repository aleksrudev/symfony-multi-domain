<?php

declare(strict_types=1);

namespace App\Inventory\Domain\Entity;

use App\Inventory\Domain\ValueObject\ProductId;
use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\GeneratedValue;
use Doctrine\ORM\Mapping\Id;
use Doctrine\ORM\Mapping\JoinColumn;
use Doctrine\ORM\Mapping\ManyToOne;
use Doctrine\ORM\Mapping\Table;
use Doctrine\ORM\Mapping\UniqueConstraint;

#[Entity]
#[Table(name: 'reservation_items', schema: 'inventory')]
#[UniqueConstraint(name: 'inventory_reservation_items_order_product_unique', columns: ['order_id', 'product_id'])]
class ReservationItem
{
    #[Id]
    #[GeneratedValue]
    #[Column(name: 'id', type: 'integer')]
    private ?int $id = null;

    #[ManyToOne(targetEntity: Reservation::class, inversedBy: 'items')]
    #[JoinColumn(name: 'order_id', referencedColumnName: 'order_id', nullable: false, onDelete: 'CASCADE')]
    private ?Reservation $reservation = null;

    #[Column(name: 'product_id', type: 'string', length: 64)]
    private string $productId;

    #[Column(name: 'quantity', type: 'integer')]
    private int $quantity;

    public function __construct(
        ProductId|string $productId,
        int $quantity,
    ) {
        if ($quantity <= 0) {
            throw new \InvalidArgumentException('Item quantity must be a positive integer.');
        }

        $this->productId = $productId instanceof ProductId ? $productId->value : $productId;
        $this->quantity = $quantity;
    }

    public function id(): ?int
    {
        return $this->id;
    }

    public function reservation(): ?Reservation
    {
        return $this->reservation;
    }

    public function setReservation(Reservation $reservation): void
    {
        $this->reservation = $reservation;
    }

    public function productId(): ProductId
    {
        return new ProductId($this->productId);
    }

    public function quantity(): int
    {
        return $this->quantity;
    }
}

