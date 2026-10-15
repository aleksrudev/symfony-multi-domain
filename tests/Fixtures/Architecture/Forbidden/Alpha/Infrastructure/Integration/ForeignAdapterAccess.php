<?php

declare(strict_types=1);

namespace App\Alpha\Infrastructure\Integration;

use App\Beta\Presentation\Adapter\AvailabilityAdapter;

final readonly class ForeignAdapterAccess
{
    public function __construct(private AvailabilityAdapter $provider)
    {
    }
}
