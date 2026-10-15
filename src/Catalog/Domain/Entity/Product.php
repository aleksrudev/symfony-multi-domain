<?php

declare(strict_types=1);

namespace App\Catalog\Domain\Entity;

use App\Catalog\Domain\Exception\InvalidProductPriceException;
use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\Id;
use Doctrine\ORM\Mapping\Table;

#[Entity]
#[Table(name: 'products', schema: 'catalog')]
class Product
{
    #[Id]
    #[Column(type: 'string', length: 64)]
    private string $id;

    #[Column(type: 'string', length: 255)]
    private string $name;

    #[Column(type: 'integer')]
    private int $priceAmount;

    #[Column(type: 'string', length: 3)]
    private string $currency;

    #[Column(type: 'boolean')]
    private bool $published;

    #[Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    public function __construct(
        string $id,
        string $name,
        int $priceAmount,
        string $currency = 'RUB',
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

    public function id(): string
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

    public function currency(): string
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
