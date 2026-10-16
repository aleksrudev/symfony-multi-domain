<?php

declare(strict_types=1);

namespace App\Ordering\Application\Query\GetDraft;

use App\Shared\Application\Bus\Query\QueryInterface;

final readonly class GetDraftQuery implements QueryInterface
{
    public function __construct(
        public string $buyerId,
    ) {
    }
}
