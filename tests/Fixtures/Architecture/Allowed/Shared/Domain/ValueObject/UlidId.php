<?php

declare(strict_types=1);

namespace App\Shared\Domain\ValueObject;

use Symfony\Component\Uid\Ulid;

abstract readonly class UlidId
{
    public string $value;

    final public function __construct(?string $value = null)
    {
        $this->value = new Ulid($value)->toBase32();
    }
}
