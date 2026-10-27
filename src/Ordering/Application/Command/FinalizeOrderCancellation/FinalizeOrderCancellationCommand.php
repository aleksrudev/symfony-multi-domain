<?php

declare(strict_types=1);

namespace App\Ordering\Application\Command\FinalizeOrderCancellation;

use App\Shared\Application\Bus\Command\CommandInterface;

final readonly class FinalizeOrderCancellationCommand implements CommandInterface
{
    public function __construct(
        public string $orderId,
    ) {
    }
}
