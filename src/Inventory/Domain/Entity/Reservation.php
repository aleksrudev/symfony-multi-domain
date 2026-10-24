<?php

declare(strict_types=1);

namespace App\Inventory\Domain\Entity;

use App\Inventory\Domain\Collection\ReservationItemCollection;
use App\Inventory\Domain\Enum\ReservationRejectionReason;
use App\Inventory\Domain\Enum\ReservationStatus;
use App\Inventory\Domain\ValueObject\OrderId;
use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\Id;
use Doctrine\ORM\Mapping\OneToMany;
use Doctrine\ORM\Mapping\Table;

#[Entity]
#[Table(name: 'reservations', schema: 'inventory')]
class Reservation
{
    #[Id]
    #[Column(name: 'order_id', type: 'inventory_order_id', length: 64)]
    private OrderId $orderId;

    #[Column(name: 'status', type: 'string', length: 32, enumType: ReservationStatus::class)]
    private ReservationStatus $status;

    #[Column(name: 'rejection_reason', type: 'string', length: 64, nullable: true, enumType: ReservationRejectionReason::class)]
    private ?ReservationRejectionReason $rejectionReason = null;

    /**
     * @var ReservationItemCollection|iterable<ReservationItem>
     */
    #[OneToMany(targetEntity: ReservationItem::class, mappedBy: 'reservation', cascade: ['persist', 'remove'], orphanRemoval: true)]
    private iterable $items;

    #[Column(name: 'created_at', type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    #[Column(name: 'updated_at', type: 'datetime_immutable')]
    private \DateTimeImmutable $updatedAt;

    public function __construct(
        OrderId $orderId,
        ReservationStatus $status,
        ?ReservationItemCollection $items = null,
        ?ReservationRejectionReason $rejectionReason = null,
        ?\DateTimeImmutable $createdAt = null,
        ?\DateTimeImmutable $updatedAt = null,
    ) {
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $this->orderId = $orderId;
        $this->status = $status;
        $this->rejectionReason = $rejectionReason;
        $this->createdAt = $createdAt ?? $now;
        $this->updatedAt = $updatedAt ?? $now;

        $itemsCollection = $items ?? new ReservationItemCollection();
        foreach ($itemsCollection as $item) {
            $item->setReservation($this);
        }
        $this->items = $itemsCollection;
    }

    public static function createReserved(OrderId $orderId, ReservationItemCollection $items): self
    {
        return new self($orderId, ReservationStatus::RESERVED, $items);
    }

    public static function createRejected(
        OrderId $orderId,
        ReservationItemCollection $items,
        ReservationRejectionReason $reason,
    ): self {
        return new self($orderId, ReservationStatus::REJECTED, $items, $reason);
    }

    public static function createDefensiveReleased(OrderId $orderId): self
    {
        return new self($orderId, ReservationStatus::RELEASED, new ReservationItemCollection());
    }

    public function orderId(): OrderId
    {
        return $this->orderId;
    }

    public function status(): ReservationStatus
    {
        return $this->status;
    }

    public function rejectionReason(): ?ReservationRejectionReason
    {
        return $this->rejectionReason;
    }

    public function items(): ReservationItemCollection
    {
        if ($this->items instanceof ReservationItemCollection) {
            return $this->items;
        }

        return new ReservationItemCollection(
            is_array($this->items) ? $this->items : iterator_to_array($this->items),
        );
    }

    public function createdAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function updatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function release(): void
    {
        $this->status = ReservationStatus::RELEASED;
        $this->updatedAt = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }

    public function hasSameItems(ReservationItemCollection $other): bool
    {
        $currentSorted = $this->items()->sortedByProductId()->all();
        $otherSorted = $other->sortedByProductId()->all();

        if (count($currentSorted) !== count($otherSorted)) {
            return false;
        }

        foreach ($currentSorted as $i => $item) {
            $otherItem = $otherSorted[$i];
            if (!$item->productId()->equals($otherItem->productId()) || $item->quantity() !== $otherItem->quantity()) {
                return false;
            }
        }

        return true;
    }
}
