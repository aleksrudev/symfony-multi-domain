<?php

declare(strict_types=1);

namespace App\Ordering\Application\Command\CancelOrder;

use App\Shared\Application\Bus\Command\CommandInterface;

final readonly class CancelOrderCommand implements CommandInterface
{
    public function __construct(
        public string $orderId,
        public ?string $buyerId = null,
        public ?string $idempotencyKey = null,
        public ?string $requestHash = null,
    ) {
    }
}
