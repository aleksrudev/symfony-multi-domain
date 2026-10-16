<?php

declare(strict_types=1);

namespace App\Ordering\Domain\Exception;

use App\Ordering\Domain\Enum\OrderStatus;
use App\Ordering\Domain\ValueObject\OrderId;
use App\Shared\Domain\Exception\DomainException;

final class OrderNotModifiableException extends DomainException
{
    public function __construct(string|OrderId $orderId, OrderStatus $status)
    {
        $id = $orderId instanceof OrderId ? $orderId->value : $orderId;
        parent::__construct(sprintf('Order "%s" cannot be modified in status "%s".', $id, $status->value));
    }
}
