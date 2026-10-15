<?php

declare(strict_types=1);

namespace App\Shared\Domain\Collection;

/** @extends \Doctrine\Common\Collections\ArrayCollection<array-key, object> */
final class SharedCollectionLeak extends \Doctrine\Common\Collections\ArrayCollection
{
}
