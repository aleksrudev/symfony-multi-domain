<?php

declare(strict_types=1);

namespace App\Beta\Presentation\Adapter;

use App\Beta\Presentation\Contracts\Availability;

final class AvailabilityAdapter implements Availability
{
    public function count(): int
    {
        return 0;
    }
}
