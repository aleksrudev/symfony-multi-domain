<?php

declare(strict_types=1);

namespace App\Inventory\Presentation\Http\Error;

use App\Inventory\Domain\Exception\StockOnHandBelowReservedException;
use App\Inventory\Domain\Exception\StockPositionNotFoundException;
use App\Shared\Presentation\Http\Error\DomainErrorProviderInterface;

final class InventoryErrorProvider implements DomainErrorProviderInterface
{
    public static function getErrors(): array
    {
        return [
            StockPositionNotFoundException::class => [
                'error' => 'stock_position_not_found',
                'status' => 404,
            ],
            StockOnHandBelowReservedException::class => [
                'error' => 'stock_on_hand_below_reserved',
                'status' => 422,
            ],
        ];
    }
}
