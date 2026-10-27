<?php

declare(strict_types=1);

namespace App\Inventory\Domain\DataProvider;

use App\Inventory\Domain\Entity\Reservation;
use App\Inventory\Domain\Exception\ReservationNotFoundException;
use App\Inventory\Domain\ValueObject\OrderId;

interface ReservationDataProviderInterface
{
    /**
     * @throws ReservationNotFoundException
     */
    public function findByOrderId(OrderId $orderId, bool $lock = false): Reservation;
}
