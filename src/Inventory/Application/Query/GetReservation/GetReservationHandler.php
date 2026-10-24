<?php

declare(strict_types=1);

namespace App\Inventory\Application\Query\GetReservation;

use App\Inventory\Domain\DataProvider\ReservationDataProviderInterface;
use App\Inventory\Domain\ValueObject\OrderId;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler(bus: 'query.bus')]
final readonly class GetReservationHandler
{
    public function __construct(
        private ReservationDataProviderInterface $reservationDataProvider,
    ) {
    }

    public function __invoke(GetReservationQuery $query): GetReservationQueryResult
    {
        $orderId = new OrderId($query->orderId);
        $reservation = $this->reservationDataProvider->findByOrderId($orderId);

        $items = [];
        foreach ($reservation->items() as $item) {
            $items[] = [
                'productId' => $item->productId()->value,
                'quantity' => $item->quantity(),
            ];
        }

        return new GetReservationQueryResult(
            orderId: $reservation->orderId()->value,
            status: $reservation->status()->value,
            rejectionReason: $reservation->rejectionReason()?->value,
            items: $items,
            updatedAt: $reservation->updatedAt()->format(\DateTimeInterface::ATOM),
        );
    }
}
