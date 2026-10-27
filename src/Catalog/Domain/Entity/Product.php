<?php

declare(strict_types=1);

namespace App\Catalog\Domain\Entity;

use App\Catalog\Domain\Enum\Currency;
use App\Catalog\Domain\Exception\InvalidProductPriceException;
use App\Catalog\Domain\ValueObject\ProductId;
use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\Id;
use Doctrine\ORM\Mapping\Table;

#[Entity]
#[Table(name: 'products', schema: 'catalog')]
class Product
{
    #[Id]
    #[Column(name: 'id', type: 'product_id', length: 64)]
    private ProductId $id;

    #[Column(name: 'name', type: 'string', length: 255)]
    private string $name;

    #[Column(name: 'price_amount', type: 'integer')]
    private int $priceAmount;

    #[Column(name: 'currency', type: 'string', length: 3, enumType: Currency::class)]
    private Currency $currency;

    #[Column(name: 'published', type: 'boolean')]
    private bool $published;

    #[Column(name: 'created_at', type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    public function __construct(
        ProductId $id,
        string $name,
        int $priceAmount,
        Currency $currency = Currency::RUB,
        bool $published = true,
        ?\DateTimeImmutable $createdAt = null,
    ) {
        if ($priceAmount <= 0) {
            throw new InvalidProductPriceException($priceAmount);
        }

        $this->id = $id;
        $this->name = $name;
        $this->priceAmount = $priceAmount;
        $this->currency = $currency;
        $this->published = $published;
        $this->createdAt = $createdAt ?? new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }

    public function id(): ProductId
    {
        return $this->id;
    }

    public function name(): string
    {
        return $this->name;
    }

    public function priceAmount(): int
    {
        return $this->priceAmount;
    }

    public function currency(): Currency
    {
        return $this->currency;
    }

    public function isPublished(): bool
    {
        return $this->published;
    }

    public function createdAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function changePrice(int $newPriceAmount): void
    {
        if ($newPriceAmount <= 0) {
            throw new InvalidProductPriceException($newPriceAmount);
        }

        $this->priceAmount = $newPriceAmount;
    }

    public function rename(string $newName): void
    {
        $this->name = $newName;
    }

    public function publish(): void
    {
        $this->published = true;
    }

    public function unpublish(): void
    {
        $this->published = false;
    }
}
