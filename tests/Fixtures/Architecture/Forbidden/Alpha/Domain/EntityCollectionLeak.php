<?php

declare(strict_types=1);

namespace App\Alpha\Domain;

use Doctrine\Common\Collections\ArrayCollection;

final readonly class EntityCollectionLeak
{
    /** @param ArrayCollection<array-key, object> $items */
    public function __construct(private ArrayCollection $items)
    {
    }
}
