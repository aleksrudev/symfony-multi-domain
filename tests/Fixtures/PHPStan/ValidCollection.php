<?php

declare(strict_types=1);

namespace App\RuleFixtures\Domain\Collection;

/** @extends \App\Shared\Domain\Collection\AbstractCollection<\stdClass> */
final class ValidCollection extends \App\Shared\Domain\Collection\AbstractCollection
{
    protected function elementClass(): string
    {
        return \stdClass::class;
    }
}
