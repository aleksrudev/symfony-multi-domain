<?php

declare(strict_types=1);

namespace App\Ordering\Domain\Exception;

use App\Ordering\Domain\ValueObject\OrderId;
use App\Shared\Domain\Exception\DomainException;

final class OrderNotFoundException extends DomainException
{
    public function __construct(string|OrderId $orderId)
    {
        $id = $orderId instanceof OrderId ? $orderId->value : $orderId;
        parent::__construct(sprintf('Order "%s" not found.', $id));
    }
}
