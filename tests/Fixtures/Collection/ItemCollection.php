<?php

declare(strict_types=1);

namespace Tests\Fixtures\Collection;

use App\Shared\Domain\Collection\AbstractCollection;

/** @extends AbstractCollection<Item> */
final class ItemCollection extends AbstractCollection
{
    protected function elementClass(): string
    {
        return Item::class;
    }
}
