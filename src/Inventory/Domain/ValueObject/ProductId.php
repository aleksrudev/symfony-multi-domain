<?php

declare(strict_types=1);

namespace App\Inventory\Domain\ValueObject;

final readonly class ProductId
{
    public string $value;

    public function __construct(string $value)
    {
        $trimmed = trim($value);
        if ('' === $trimmed) {
            throw new \InvalidArgumentException('Product ID cannot be empty.');
        }

        $this->value = $trimmed;
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }

    public function __toString(): string
    {
        return $this->value;
    }
}
