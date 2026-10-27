<?php

declare(strict_types=1);

namespace App\Notifications\Domain\Enum;

enum NotificationType: string
{
    case ORDER_CONFIRMED = 'order_confirmed';
    case ORDER_REJECTED = 'order_rejected';
    case ORDER_CANCELLED = 'order_cancelled';
}
