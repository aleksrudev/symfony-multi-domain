<?php

declare(strict_types=1);

namespace App\Ordering\Domain\Enum;

enum OrderStatus: string
{
    case DRAFT = 'draft';
    case PENDING_STOCK = 'pending_stock';
    case CONFIRMED = 'confirmed';
    case REJECTED = 'rejected';
    case CANCELLING = 'cancelling';
    case CANCELLED = 'cancelled';
}
