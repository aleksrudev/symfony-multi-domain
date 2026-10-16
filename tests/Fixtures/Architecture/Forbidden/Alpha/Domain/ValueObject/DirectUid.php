<?php

declare(strict_types=1);

namespace App\Alpha\Domain\ValueObject;

use App\Shared\Domain\ValueObject\UuidId;
use Symfony\Component\Uid\Uuid;

final readonly class DirectUid extends UuidId
{
    public function frameworkValue(): Uuid
    {
        return Uuid::v4();
    }
}
