<?php

declare(strict_types=1);

namespace App\Ordering\Application\Command\CreateDraft;

use App\Shared\Application\Bus\Command\CommandInterface;

final readonly class CreateDraftCommand implements CommandInterface
{
    public function __construct(
        public string $buyerId,
        public ?string $idempotencyKey = null,
        public string $requestHash = '',
    ) {
    }
}
