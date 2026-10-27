<?php

declare(strict_types=1);

namespace App\Ordering\Infrastructure\Integration\Inventory;

use App\Inventory\Presentation\Contracts\InventoryContractInterface;
use App\Ordering\Application\Query\GetOrderDiagnostics\Provider\OrderReservationStatus;
use App\Ordering\Application\Query\GetOrderDiagnostics\Provider\OrderReservationStatusProviderInterface;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

#[AsAlias(OrderReservationStatusProviderInterface::class)]
final readonly class InventoryReservationStatusProvider implements OrderReservationStatusProviderInterface
{
    public function __construct(
        private InventoryContractInterface $inventoryContract,
    ) {
    }

    public function getReservationStatus(string $orderId): OrderReservationStatus
    {
        $result = $this->inventoryContract->getReservationResult($orderId);

        return new OrderReservationStatus(
            orderId: $result->orderId,
            status: $result->status,
            rejectionReason: $result->rejectionReason,
        );
    }
}

