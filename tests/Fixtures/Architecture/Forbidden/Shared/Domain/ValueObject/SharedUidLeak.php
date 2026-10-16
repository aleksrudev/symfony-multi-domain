<?php

declare(strict_types=1);

namespace App\Shared\Domain\ValueObject;

use Symfony\Component\Uid\Ulid;

final class SharedUidLeak
{
    public function frameworkValue(): Ulid
    {
        return new Ulid();
    }
}
