<?php

declare(strict_types=1);

namespace App\Shared\Domain\ValueObject;

use Symfony\Component\Uid\Ulid;

abstract readonly class UlidId implements \Stringable
{
    public string $value;

    final public function __construct(?string $value = null)
    {
        $this->value = new Ulid($value)->toBase32();
    }

    final public function equals(self $other): bool
    {
        return $this::class === $other::class && $this->value === $other->value;
    }

    final public function __toString(): string
    {
        return $this->value;
    }
}
