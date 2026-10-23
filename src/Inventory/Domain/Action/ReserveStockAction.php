<?php

declare(strict_types=1);

namespace App\Inventory\Domain\Action;

use App\Inventory\Domain\Collection\ReservationItemCollection;
use App\Inventory\Domain\DataProvider\ReservationDataProviderInterface;
use App\Inventory\Domain\DataProvider\StockPositionDataProviderInterface;
use App\Inventory\Domain\Entity\Reservation;
use App\Inventory\Domain\Enum\ReservationRejectionReason;
use App\Inventory\Domain\Enum\ReservationStatus;
use App\Inventory\Domain\Exception\ReservationConflictException;
use App\Inventory\Domain\Exception\ReservationNotFoundException;
use App\Inventory\Domain\Exception\StockPositionNotFoundException;
use App\Inventory\Domain\Repository\ReservationRepositoryInterface;
use App\Inventory\Domain\Repository\StockPositionRepositoryInterface;
use App\Inventory\Domain\ValueObject\OrderId;

final readonly class ReserveStockAction
{
    public function __construct(
        private StockPositionDataProviderInterface $stockPositionDataProvider,
        private StockPositionRepositoryInterface $stockPositionRepository,
        private ReservationDataProviderInterface $reservationDataProvider,
        private ReservationRepositoryInterface $reservationRepository,
    ) {
    }

    public function __invoke(OrderId $orderId, ReservationItemCollection $items): Reservation
    {
        try {
            $existing = $this->reservationDataProvider->findByOrderId($orderId, lock: true);
            if (ReservationStatus::RELEASED === $existing->status()) {
                // Defensive cancellation came first. Do not reserve stock.
                return $existing;
            }

            if (!$existing->hasSameItems($items)) {
                throw new ReservationConflictException($orderId->value);
            }

            return $existing;
        } catch (ReservationNotFoundException) {
            // No prior reservation, proceed with reservation attempt
        }

        $sortedItems = $items->sortedByProductId();
        $positions = [];
        $canReserveAll = true;
        $rejectionReason = null;

        // Lock positions in ascending order of productId to prevent deadlocks
        foreach ($sortedItems as $item) {
            try {
                $position = $this->stockPositionDataProvider->findByProductId($item->productId(), lock: true);
                $positions[$item->productId()->value] = $position;

                if ($position->available() < $item->quantity()) {
                    $canReserveAll = false;
                    $rejectionReason = ReservationRejectionReason::INSUFFICIENT_STOCK;
                }
            } catch (StockPositionNotFoundException) {
                $canReserveAll = false;
                $rejectionReason = ReservationRejectionReason::PRODUCT_NOT_FOUND;
            }
        }

        if ($canReserveAll) {
            foreach ($sortedItems as $item) {
                $position = $positions[$item->productId()->value];
                $position->reserve($item->quantity());
                $this->stockPositionRepository->save($position);
            }

            $reservation = Reservation::createReserved($orderId, $sortedItems);
            $this->reservationRepository->save($reservation);

            return $reservation;
        }

        // Atomic failure: no stock positions are modified, record rejected reservation
        $reservation = Reservation::createRejected(
            $orderId,
            $sortedItems,
            $rejectionReason ?? ReservationRejectionReason::INSUFFICIENT_STOCK,
        );
        $this->reservationRepository->save($reservation);

        return $reservation;
    }
}

