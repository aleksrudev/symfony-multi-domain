<?php

declare(strict_types=1);

namespace App\Ordering\Domain\Entity;

use App\Ordering\Domain\Exception\InvalidOrderItemQuantityException;
use App\Ordering\Domain\ValueObject\ProductId;
use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\GeneratedValue;
use Doctrine\ORM\Mapping\Id;
use Doctrine\ORM\Mapping\JoinColumn;
use Doctrine\ORM\Mapping\ManyToOne;
use Doctrine\ORM\Mapping\Table;
use Doctrine\ORM\Mapping\UniqueConstraint;

#[Entity]
#[Table(name: 'order_items', schema: 'ordering')]
#[UniqueConstraint(name: 'ordering_order_items_order_product_unique', columns: ['order_id', 'product_id'])]
class OrderItem
{
    #[Id]
    #[GeneratedValue]
    #[Column(name: 'id', type: 'integer')]
    private ?int $id;

    #[ManyToOne(targetEntity: Order::class, inversedBy: 'items')]
    #[JoinColumn(name: 'order_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private ?Order $order = null;

    #[Column(name: 'product_id', type: 'string', length: 64)]
    private string $productId;

    #[Column(name: 'quantity', type: 'integer')]
    private int $quantity;

    #[Column(name: 'product_name', type: 'string', length: 255, nullable: true)]
    private ?string $productName = null;

    #[Column(name: 'price_amount', type: 'integer', nullable: true)]
    private ?int $priceAmount = null;

    #[Column(name: 'currency', type: 'string', length: 3, nullable: true, enumType: \App\Ordering\Domain\Enum\Currency::class)]
    private ?\App\Ordering\Domain\Enum\Currency $currency = null;

    public function __construct(
        ProductId $productId,
        int $quantity,
        ?int $id = null,
        ?string $productName = null,
        ?int $priceAmount = null,
        ?\App\Ordering\Domain\Enum\Currency $currency = null,
    ) {
        if ($quantity <= 0) {
            throw new InvalidOrderItemQuantityException($quantity);
        }

        $this->id = $id;
        $this->productId = $productId->value;
        $this->quantity = $quantity;
        $this->productName = $productName;
        $this->priceAmount = $priceAmount;
        $this->currency = $currency;
    }

    public function id(): ?int
    {
        return $this->id;
    }

    public function productId(): ProductId
    {
        return new ProductId($this->productId);
    }

    public function quantity(): int
    {
        return $this->quantity;
    }

    public function productName(): ?string
    {
        return $this->productName;
    }

    public function priceAmount(): ?int
    {
        return $this->priceAmount;
    }

    public function currency(): ?\App\Ordering\Domain\Enum\Currency
    {
        return $this->currency;
    }

    public function attachSnapshot(string $productName, int $priceAmount, \App\Ordering\Domain\Enum\Currency $currency): void
    {
        if ($priceAmount <= 0) {
            throw new \InvalidArgumentException('Price amount must be positive.');
        }

        $this->productName = $productName;
        $this->priceAmount = $priceAmount;
        $this->currency = $currency;
    }

    public function order(): ?Order
    {
        return $this->order;
    }

    public function setOrder(Order $order): void
    {
        $this->order = $order;
    }
}
