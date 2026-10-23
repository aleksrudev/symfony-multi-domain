<?php

declare(strict_types=1);

namespace App\Inventory\Presentation\Contracts\Dto;

final readonly class ReleaseRequest
{
    public function __construct(
        public string $orderId,
    ) {
    }
}

