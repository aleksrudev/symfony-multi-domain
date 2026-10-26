<?php

declare(strict_types=1);

namespace Tests\Fixtures\Messaging\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'outbox_test_business', schema: 'public')]
final class OutboxBusinessRecord
{
    public function __construct(
        #[ORM\Id]
        #[ORM\Column(name: 'operation_id', type: 'text')]
        public string $operationId,
    ) {
    }
}
