<?php

declare(strict_types=1);

namespace App\Inventory\Application\Command\RequestRelease;

interface ReleaseQueuePortInterface
{
    public function enqueue(string $orderId): void;
}
