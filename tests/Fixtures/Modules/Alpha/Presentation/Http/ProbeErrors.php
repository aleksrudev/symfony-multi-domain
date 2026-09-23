<?php

declare(strict_types=1);

namespace Tests\Fixtures\Modules\Alpha\Presentation\Http;

use App\Shared\Presentation\Http\Error\DomainErrorProviderInterface;
use Tests\Fixtures\Modules\Alpha\Domain\ProbeFailure;

final class ProbeErrors implements DomainErrorProviderInterface
{
    public static function getErrors(): array
    {
        return [ProbeFailure::class => ['error' => 'alpha.probe', 'status' => 409]];
    }
}
