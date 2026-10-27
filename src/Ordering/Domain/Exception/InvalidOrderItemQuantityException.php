<?php

declare(strict_types=1);

namespace App\Ordering\Domain\Exception;

use App\Shared\Domain\Exception\DomainException;

final class InvalidOrderItemQuantityException extends DomainException
{
    public function __construct(int $quantity)
    {
        parent::__construct(sprintf('Order item quantity must be positive, got %d.', $quantity));
    }
}
