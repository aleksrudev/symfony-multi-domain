<?php

declare(strict_types=1);

namespace App\Beta\Presentation\Contracts\Dto;

use App\Beta\Domain\Model;

final readonly class InternalModelLeak
{
    public function __construct(public Model $model)
    {
    }
}
