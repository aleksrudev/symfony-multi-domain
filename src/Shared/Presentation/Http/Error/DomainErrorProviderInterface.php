<?php

declare(strict_types=1);

namespace App\Shared\Presentation\Http\Error;

interface DomainErrorProviderInterface
{
    /** @return array<string, mixed> Raw error definitions, validated during container compilation. */
    public static function getErrors(): array;
}
