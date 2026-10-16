<?php

declare(strict_types=1);

namespace App\Shared\Domain\ValueObject;

use Symfony\Component\Uid\Uuid;

abstract readonly class UuidId
{
    public string $value;

    final public function __construct(?string $value = null)
    {
        $this->value = (null === $value ? Uuid::v4() : new Uuid($value, true))->toRfc4122();
    }
}
