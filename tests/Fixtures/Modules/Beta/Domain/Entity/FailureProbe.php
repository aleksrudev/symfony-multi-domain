<?php

declare(strict_types=1);

namespace Tests\Fixtures\Modules\Beta\Domain\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'failure_probe', schema: 'beta')]
final class FailureProbe
{
    public function __construct(
        #[ORM\Id]
        #[ORM\Column(type: 'text')]
        public string $id,
        #[ORM\Column(type: 'integer')]
        public int $value,
    ) {
    }
}
