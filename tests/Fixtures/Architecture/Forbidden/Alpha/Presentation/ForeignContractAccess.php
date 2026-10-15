<?php

declare(strict_types=1);

namespace App\Alpha\Presentation;

use App\Beta\Presentation\Contracts\Availability;

final readonly class ForeignContractAccess
{
    public function __construct(private Availability $provider)
    {
    }
}
