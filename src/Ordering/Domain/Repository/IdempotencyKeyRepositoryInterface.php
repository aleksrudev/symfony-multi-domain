<?php

declare(strict_types=1);

namespace App\Ordering\Domain\Repository;

use App\Ordering\Domain\Entity\IdempotencyKey;

interface IdempotencyKeyRepositoryInterface
{
    public function save(IdempotencyKey $idempotencyKey): void;

    public function remove(IdempotencyKey $idempotencyKey): void;
}
