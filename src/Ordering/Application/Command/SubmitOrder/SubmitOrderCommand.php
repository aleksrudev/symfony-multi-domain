<?php

declare(strict_types=1);

namespace App\Ordering\Application\Command\SubmitOrder;

use App\Shared\Application\Bus\Command\CommandInterface;

final readonly class SubmitOrderCommand implements CommandInterface
{
    public function __construct(
        public string $orderId,
        public string $buyerId,
        public ?string $idempotencyKey = null,
        public string $requestHash = '',
    ) {
    }
}

