<?php

declare(strict_types=1);

namespace App\Ordering\Domain\Enum;

enum IdempotencyOperation: string
{
    case CREATE_DRAFT = 'create_draft';
}

