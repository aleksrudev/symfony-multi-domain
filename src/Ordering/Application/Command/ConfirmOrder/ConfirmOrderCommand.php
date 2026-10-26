<?php

declare(strict_types=1);

namespace App\Ordering\Application\Command\ConfirmOrder;

use App\Shared\Application\Bus\Command\CommandInterface;

final readonly class ConfirmOrderCommand implements CommandInterface
{
    public function __construct(
        public string $orderId,
    ) {
    }
}
