<?php

declare(strict_types=1);

namespace Tests\Fixtures\Modules\Alpha\Domain\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'records', schema: 'alpha')]
class Record
{
    public function __construct(#[ORM\Id] #[ORM\Column(type: 'integer')] private int $id)
    {
    }

    public function id(): int
    {
        return $this->id;
    }
}
