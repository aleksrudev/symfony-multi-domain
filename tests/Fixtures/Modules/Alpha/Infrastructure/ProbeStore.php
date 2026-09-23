<?php

declare(strict_types=1);

namespace Tests\Fixtures\Modules\Alpha\Infrastructure;

use Doctrine\DBAL\Connection;
use Tests\Fixtures\Modules\Alpha\Application\ProbeStoreInterface;

final readonly class ProbeStore implements ProbeStoreInterface
{
    public function __construct(private Connection $connection)
    {
    }

    public function append(int $value): void
    {
        $this->connection->insert('txn_probe', ['value' => $value]);
    }
}
