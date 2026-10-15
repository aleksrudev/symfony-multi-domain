<?php

declare(strict_types=1);

namespace App\Alpha\Domain\Collection;

use Doctrine\ORM\EntityManagerInterface;

final readonly class OrmCollectionLeak
{
    public function __construct(private EntityManagerInterface $manager)
    {
    }
}
