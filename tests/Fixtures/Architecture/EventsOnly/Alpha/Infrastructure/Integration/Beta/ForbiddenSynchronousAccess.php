<?php

declare(strict_types=1);

namespace App\Alpha\Infrastructure\Integration\Beta;

use App\Beta\Presentation\Contracts\Availability;

final readonly class ForbiddenSynchronousAccess
{
    public function __construct(private Availability $value)
    {
    }
}
