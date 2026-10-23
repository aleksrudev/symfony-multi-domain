<?php

declare(strict_types=1);

namespace App\Inventory\Domain\Exception;

use App\Shared\Domain\Exception\DomainException;

final class StockOnHandBelowReservedException extends DomainException
{
    public function __construct(int $onHand, int $reserved)
    {
        parent::__construct(sprintf('Cannot set on-hand stock to %d because it is below reserved quantity %d.', $onHand, $reserved));
    }
}
