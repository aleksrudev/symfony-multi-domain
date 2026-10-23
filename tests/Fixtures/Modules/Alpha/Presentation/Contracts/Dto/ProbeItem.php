<?php

declare(strict_types=1);

namespace Tests\Fixtures\Modules\Alpha\Presentation\Contracts\Dto;

final readonly class ProbeItem
{
    public function __construct(public string $code, public int $quantity)
    {
    }
}
