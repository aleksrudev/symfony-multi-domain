<?php

declare(strict_types=1);

namespace App\Alpha\Domain\Collection;

use App\Shared\Domain\Collection\AbstractCollection;
use Doctrine\Common\Collections\Criteria;

/** @extends AbstractCollection<\stdClass> */
final class BaseWithDoctrineLeakCollection extends AbstractCollection
{
    protected function elementClass(): string
    {
        return \stdClass::class;
    }

    public function useCriteria(Criteria $criteria): void
    {
    }
}
