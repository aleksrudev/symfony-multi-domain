<?php

declare(strict_types=1);

namespace App\Alpha\Domain\Collection;

use App\Alpha\Domain\Model;
use App\Shared\Domain\Collection\AbstractCollection;

/** @extends AbstractCollection<Model> */
final class ModelCollection extends AbstractCollection
{
    protected function elementClass(): string
    {
        return Model::class;
    }
}
