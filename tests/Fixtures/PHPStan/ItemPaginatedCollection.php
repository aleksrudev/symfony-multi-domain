<?php

declare(strict_types=1);

namespace App\RuleFixtures\Domain\Collection;

final readonly class ItemPaginatedCollection
{
    public function __construct(public int $count)
    {
    }
}
