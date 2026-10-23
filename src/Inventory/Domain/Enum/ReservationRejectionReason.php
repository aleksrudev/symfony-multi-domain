<?php

declare(strict_types=1);

namespace App\Inventory\Domain\Enum;

enum ReservationRejectionReason: string
{
    case INSUFFICIENT_STOCK = 'insufficient_stock';
    case PRODUCT_NOT_FOUND = 'product_not_found';
}

