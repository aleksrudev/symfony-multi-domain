<?php

declare(strict_types=1);

namespace App\Ordering\Application\Query\GetOrderDiagnostics\Provider;

interface OrderNotificationStatusProviderInterface
{
    /**
     * @return list<OrderNotificationStatus>
     */
    public function getNotificationsForOrder(string $orderId): array;
}
