<?php

declare(strict_types=1);

namespace App\Shared\Domain\Collection;

/**
 * @template T of object
 * @extends \Doctrine\Common\Collections\ArrayCollection<array-key, T>
 */
abstract class AbstractCollection extends \Doctrine\Common\Collections\ArrayCollection
{
}
