<?php

declare(strict_types=1);

namespace App\Beta\Application\Query;

use App\Beta\Presentation\Contracts\Availability;

final readonly class ApplicationContractAccess
{
    public function __construct(private Availability $provider)
    {
    }
}
