<?php

declare(strict_types=1);

namespace App\Shared\Presentation\Http\Error;

use App\Shared\Domain\Exception\DomainException;

final readonly class DomainErrorRegistry
{
    /** @param array<class-string<DomainException>, array{error: string, status: int}> $errors */
    public function __construct(private array $errors = [])
    {
    }

    /** @return array{error: string, status: int}|null */
    public function find(DomainException $exception): ?array
    {
        return $this->errors[$exception::class] ?? null;
    }
}
