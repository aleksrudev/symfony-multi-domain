<?php

declare(strict_types=1);

namespace App\Shared\Domain\ValueObject;

use Symfony\Component\Uid\Uuid;

abstract readonly class UuidId implements \Stringable
{
    public string $value;

    final public function __construct(?string $value = null)
    {
        $this->value = (null === $value ? Uuid::v4() : new Uuid($value, true))->toRfc4122();
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
