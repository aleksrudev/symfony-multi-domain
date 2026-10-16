<?php

declare(strict_types=1);

namespace App\Ordering\Domain\Exception;

use App\Shared\Domain\Exception\DomainException;

final class EmptyOrderItemsException extends DomainException
{
    public function __construct()
    {
        parent::__construct('Order items cannot be empty.');
    }
}
