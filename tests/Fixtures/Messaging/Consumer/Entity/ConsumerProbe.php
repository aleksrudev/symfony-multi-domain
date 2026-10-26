<?php

declare(strict_types=1);

namespace Tests\Fixtures\Messaging\Consumer\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'consumer_test_probe', schema: 'public')]
final class ConsumerProbe
{
    public function __construct(
        #[ORM\Id]
        #[ORM\Column(type: 'text')]
        public string $id,
        #[ORM\Column(type: 'integer')]
        public int $value = 0,
    ) {
    }
}
