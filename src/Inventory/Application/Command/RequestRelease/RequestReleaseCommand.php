<?php

declare(strict_types=1);

namespace App\Inventory\Application\Command\RequestRelease;

use App\Shared\Application\Bus\Command\CommandInterface;

final readonly class RequestReleaseCommand implements CommandInterface
{
    public function __construct(
        public string $orderId,
    ) {
    }
}
