<?php

declare(strict_types=1);

namespace App\Alpha\Domain;

use App\Alpha\Domain\Collection\ModelCollection;

final readonly class CollectionOwner
{
    public function __construct(private ModelCollection $items)
    {
    }
}
