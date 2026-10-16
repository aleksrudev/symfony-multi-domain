<?php

declare(strict_types=1);

namespace App\Ordering\Domain\DataProvider;

use App\Ordering\Domain\Entity\IdempotencyKey;
use App\Ordering\Domain\Exception\IdempotencyKeyNotFoundException;
use App\Ordering\Domain\ValueObject\BuyerId;

interface IdempotencyKeyDataProviderInterface
{
    /**
     * @throws IdempotencyKeyNotFoundException
     */
    public function findByKeyAndBuyerId(string $key, BuyerId $buyerId): IdempotencyKey;
}

