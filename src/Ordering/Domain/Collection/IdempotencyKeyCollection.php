<?php

declare(strict_types=1);

namespace App\Ordering\Domain\Collection;

use App\Ordering\Domain\Entity\IdempotencyKey;
use App\Shared\Domain\Collection\AbstractCollection;

/**
 * @extends AbstractCollection<IdempotencyKey>
 */
final class IdempotencyKeyCollection extends AbstractCollection
{
    protected function elementClass(): string
    {
        return IdempotencyKey::class;
    }
}
