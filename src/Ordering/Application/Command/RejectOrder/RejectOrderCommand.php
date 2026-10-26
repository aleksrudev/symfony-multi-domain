<?php

declare(strict_types=1);

namespace App\Ordering\Application\Command\RejectOrder;

use App\Shared\Application\Bus\Command\CommandInterface;

final readonly class RejectOrderCommand implements CommandInterface
{
    public function __construct(
        public string $orderId,
        public string $reason,
    ) {
    }
}
