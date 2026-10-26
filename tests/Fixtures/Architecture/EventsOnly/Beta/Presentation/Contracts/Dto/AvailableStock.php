<?php

declare(strict_types=1);

namespace App\Beta\Presentation\Contracts\Dto;

final readonly class AvailableStock
{
    public function __construct(public int $count)
    {
    }
}
