<?php

declare(strict_types=1);

namespace App\Inventory\Domain\Action;

use App\Inventory\Domain\DataProvider\ReservationDataProviderInterface;
use App\Inventory\Domain\DataProvider\StockPositionDataProviderInterface;
use App\Inventory\Domain\Entity\Reservation;
use App\Inventory\Domain\Enum\ReservationStatus;
use App\Inventory\Domain\Exception\ReservationNotFoundException;
use App\Inventory\Domain\Exception\StockPositionNotFoundException;
use App\Inventory\Domain\Repository\ReservationRepositoryInterface;
use App\Inventory\Domain\Repository\StockPositionRepositoryInterface;
use App\Inventory\Domain\ValueObject\OrderId;

final readonly class ReleaseStockAction
{
    public function __construct(
        private StockPositionDataProviderInterface $stockPositionDataProvider,
        private StockPositionRepositoryInterface $stockPositionRepository,
        private ReservationDataProviderInterface $reservationDataProvider,
        private ReservationRepositoryInterface $reservationRepository,
    ) {
    }

    public function __invoke(OrderId $orderId): Reservation
    {
        try {
            $reservation = $this->reservationDataProvider->findByOrderId($orderId, lock: true);
        } catch (ReservationNotFoundException) {
            // Defensive record: Release arrived before Reserve
            $reservation = Reservation::createDefensiveReleased($orderId);
            $this->reservationRepository->save($reservation);

            return $reservation;
        }

        if (ReservationStatus::RESERVED === $reservation->status()) {
            $sortedItems = $reservation->items()->sortedByProductId();

            // Release positions in ascending order of productId
            foreach ($sortedItems as $item) {
                try {
                    $position = $this->stockPositionDataProvider->findByProductId($item->productId(), lock: true);
                    $position->release($item->quantity());
                    $this->stockPositionRepository->save($position);
                } catch (StockPositionNotFoundException) {
                    // Position could not be found, continue releasing other items
                }
            }

            $reservation->release();
            $this->reservationRepository->save($reservation);
        }

        // If already RELEASED or REJECTED: idempotent no-op
        return $reservation;
    }
}
