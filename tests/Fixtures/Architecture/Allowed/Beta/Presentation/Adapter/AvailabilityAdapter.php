<?php

declare(strict_types=1);

namespace App\Beta\Presentation\Adapter;

use App\Beta\Presentation\Contracts\Availability;
use App\Beta\Presentation\Contracts\Dto\AvailableStock;

final class AvailabilityAdapter implements Availability
{
    public function getStock(): AvailableStock
    {
        return new AvailableStock(1);
    }
}
