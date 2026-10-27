<?php

declare(strict_types=1);

namespace App\Ordering\Infrastructure\Integration\Inventory;

use App\Inventory\Presentation\Contracts\InventoryContractInterface;
use App\Ordering\Application\Command\ReconcileStock\Provider\ReservationResult;
use App\Ordering\Application\Command\ReconcileStock\Provider\ReservationResultProviderInterface;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

#[AsAlias(ReservationResultProviderInterface::class)]
final readonly class InventoryReservationResultProvider implements ReservationResultProviderInterface
{
    public function __construct(
        private InventoryContractInterface $inventoryContract,
    ) {
    }

    public function getReservationResult(string $orderId): ReservationResult
    {
        $result = $this->inventoryContract->getReservationResult($orderId);

        return new ReservationResult(
            orderId: $result->orderId,
            status: $result->status,
            rejectionReason: $result->rejectionReason,
        );
    }
}
