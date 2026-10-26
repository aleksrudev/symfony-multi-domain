<?php

declare(strict_types=1);

namespace App\Alpha\Infrastructure\Integration\Beta;

use App\Beta\Presentation\Contracts\Dto\AvailableStock;

final readonly class AllowedDtoAccess
{
    public function __construct(private AvailableStock $value)
    {
    }
}
