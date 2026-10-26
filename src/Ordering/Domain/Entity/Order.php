<?php

declare(strict_types=1);

namespace App\Ordering\Domain\Entity;

use App\Ordering\Domain\Collection\OrderItemCollection;
use App\Ordering\Domain\Collection\ProductSnapshotCollection;
use App\Ordering\Domain\Enum\Currency;
use App\Ordering\Domain\Enum\OrderStatus;
use App\Ordering\Domain\Exception\EmptyOrderItemsException;
use App\Ordering\Domain\Exception\OrderNotModifiableException;
use App\Ordering\Domain\Exception\ProductNotAvailableException;
use App\Ordering\Domain\ValueObject\BuyerId;
use App\Ordering\Domain\ValueObject\OrderId;
use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\Id;
use Doctrine\ORM\Mapping\OneToMany;
use Doctrine\ORM\Mapping\Table;

#[Entity]
#[Table(name: 'orders', schema: 'ordering')]
class Order
{
    #[Id]
    #[Column(name: 'id', type: 'order_id', length: 64)]
    private OrderId $id;

    #[Column(name: 'buyer_id', type: 'string', length: 64)]
    private string $buyerId;

    #[Column(name: 'status', type: 'string', length: 32, enumType: OrderStatus::class)]
    private OrderStatus $status;

    #[Column(name: 'total_amount', type: 'integer', nullable: true)]
    private ?int $totalAmount = null;

    #[Column(name: 'currency', type: 'string', length: 3, nullable: true, enumType: Currency::class)]
    private ?Currency $currency = null;

    #[Column(name: 'rejection_reason', type: 'string', length: 64, nullable: true)]
    private ?string $rejectionReason = null;

    #[Column(name: 'next_check_at', type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $nextCheckAt = null;

    /**
     * @var OrderItemCollection|iterable<OrderItem>
     */
    #[OneToMany(targetEntity: OrderItem::class, mappedBy: 'order', cascade: ['persist', 'remove'], orphanRemoval: true)]
    private iterable $items;

    #[Column(name: 'created_at', type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    #[Column(name: 'updated_at', type: 'datetime_immutable')]
    private \DateTimeImmutable $updatedAt;

    public function __construct(
        OrderId $id,
        BuyerId $buyerId,
        OrderStatus $status = OrderStatus::DRAFT,
        ?\DateTimeImmutable $createdAt = null,
        ?\DateTimeImmutable $updatedAt = null,
        ?int $totalAmount = null,
        ?Currency $currency = null,
        ?string $rejectionReason = null,
        ?\DateTimeImmutable $nextCheckAt = null,
    ) {
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $this->id = $id;
        $this->buyerId = $buyerId->value;
        $this->status = $status;
        $this->items = new OrderItemCollection();
        $this->createdAt = $createdAt ?? $now;
        $this->updatedAt = $updatedAt ?? $now;
        $this->totalAmount = $totalAmount;
        $this->currency = $currency;
        $this->rejectionReason = $rejectionReason;
        $this->nextCheckAt = $nextCheckAt;
    }

    public static function createDraft(
        OrderId $id,
        BuyerId $buyerId,
        ?\DateTimeImmutable $createdAt = null,
    ): self {
        return new self($id, $buyerId, OrderStatus::DRAFT, $createdAt);
    }

    public function id(): OrderId
    {
        return $this->id;
    }

    public function buyerId(): BuyerId
    {
        return new BuyerId($this->buyerId);
    }

    public function status(): OrderStatus
    {
        return $this->status;
    }

    public function createdAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function updatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function items(): OrderItemCollection
    {
        if ($this->items instanceof OrderItemCollection) {
            return $this->items;
        }

        return new OrderItemCollection(is_array($this->items) ? $this->items : iterator_to_array($this->items));
    }

    public function replaceItems(OrderItemCollection $newItems): void
    {
        if (OrderStatus::DRAFT !== $this->status) {
            throw new OrderNotModifiableException($this->id, $this->status);
        }

        if ($newItems->isEmpty()) {
            throw new EmptyOrderItemsException();
        }

        $items = $this->items;
        if ($items instanceof OrderItemCollection) {
            $items->clear();
            foreach ($newItems as $item) {
                $item->setOrder($this);
                $items->add($item);
            }
        } elseif (is_object($items) && method_exists($items, 'clear') && method_exists($items, 'add')) {
            $items->clear();
            foreach ($newItems as $item) {
                $item->setOrder($this);
                $items->add($item);
            }
        } else {
            foreach ($newItems as $item) {
                $item->setOrder($this);
            }
            $this->items = $newItems;
        }

        $this->updatedAt = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }

    public function totalAmount(): ?int
    {
        return $this->totalAmount;
    }

    public function currency(): ?Currency
    {
        return $this->currency;
    }

    public function rejectionReason(): ?string
    {
        return $this->rejectionReason;
    }

    public function nextCheckAt(): ?\DateTimeImmutable
    {
        return $this->nextCheckAt;
    }

    public function confirm(): void
    {
        if (OrderStatus::CONFIRMED === $this->status) {
            return;
        }

        if (OrderStatus::PENDING_STOCK !== $this->status) {
            throw new OrderNotModifiableException($this->id, $this->status);
        }

        $this->status = OrderStatus::CONFIRMED;
        $this->nextCheckAt = null;
        $this->updatedAt = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }

    public function reject(string $reason): void
    {
        if (OrderStatus::REJECTED === $this->status) {
            return;
        }

        if (OrderStatus::PENDING_STOCK !== $this->status) {
            throw new OrderNotModifiableException($this->id, $this->status);
        }

        $this->status = OrderStatus::REJECTED;
        $this->rejectionReason = $reason;
        $this->nextCheckAt = null;
        $this->updatedAt = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }

    public function rescheduleCheck(\DateTimeImmutable $nextCheckAt): void
    {
        if (OrderStatus::PENDING_STOCK !== $this->status && OrderStatus::CANCELLING !== $this->status) {
            return;
        }

        $this->nextCheckAt = $nextCheckAt;
        $this->updatedAt = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }

    public function cancel(): void
    {
        if (OrderStatus::CANCELLING === $this->status || OrderStatus::CANCELLED === $this->status) {
            return;
        }

        if (OrderStatus::PENDING_STOCK !== $this->status && OrderStatus::CONFIRMED !== $this->status) {
            throw new OrderNotModifiableException($this->id, $this->status);
        }

        $this->status = OrderStatus::CANCELLING;
        $this->updatedAt = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $this->nextCheckAt = $this->updatedAt;
    }

    public function finalizeCancellation(): void
    {
        if (OrderStatus::CANCELLED === $this->status) {
            return;
        }

        if (OrderStatus::CANCELLING !== $this->status) {
            throw new OrderNotModifiableException($this->id, $this->status);
        }

        $this->status = OrderStatus::CANCELLED;
        $this->nextCheckAt = null;
        $this->updatedAt = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }

    public function submit(ProductSnapshotCollection $snapshots): void
    {
        if (OrderStatus::DRAFT !== $this->status) {
            throw new OrderNotModifiableException($this->id, $this->status);
        }

        $items = $this->items();
        if ($items->isEmpty()) {
            throw new EmptyOrderItemsException();
        }

        $totalAmount = 0;
        $orderCurrency = Currency::RUB;

        foreach ($items as $item) {
            $snapshot = $snapshots->findByProductId($item->productId());
            if (null === $snapshot) {
                throw new ProductNotAvailableException($item->productId());
            }

            $item->attachSnapshot($snapshot->name, $snapshot->priceAmount, $snapshot->currency);
            $totalAmount += $item->quantity() * $snapshot->priceAmount;
            $orderCurrency = $snapshot->currency;
        }

        $this->totalAmount = $totalAmount;
        $this->currency = $orderCurrency;
        $this->status = OrderStatus::PENDING_STOCK;
        $this->updatedAt = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $this->nextCheckAt = $this->updatedAt;
    }
}
