<?php

declare(strict_types=1);

namespace App\Ordering\Domain\Exception;

use App\Shared\Domain\Exception\DomainException;

final class IdempotencyConflictException extends DomainException
{
    public function __construct(string $key)
    {
        parent::__construct(sprintf('Idempotency key "%s" has already been used with different request parameters.', $key));
    }
}
