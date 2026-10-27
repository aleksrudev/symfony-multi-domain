<?php

declare(strict_types=1);

namespace App\Ordering\Application\Query\GetOrderDiagnostics;

use App\Ordering\Application\Query\GetOrderDiagnostics\Provider\OrderNotificationStatus;
use App\Ordering\Application\Query\GetOrderDiagnostics\Provider\OrderNotificationStatusProviderInterface;
use App\Ordering\Application\Query\GetOrderDiagnostics\Provider\OrderReservationStatusProviderInterface;
use App\Ordering\Application\Query\GetOrderDiagnostics\Result\GetOrderDiagnosticsQueryResult;
use App\Ordering\Application\Query\GetOrderDiagnostics\Result\OrderDiagnosticItemResult;
use App\Ordering\Application\Query\GetOrderDiagnostics\Result\OrderDiagnosticResult;
use App\Ordering\Application\Query\GetOrderDiagnostics\Result\OrderInventoryDiagnosticResult;
use App\Ordering\Application\Query\GetOrderDiagnostics\Result\OrderNotificationDiagnosticResult;
use App\Ordering\Domain\DataProvider\OrderDataProviderInterface;
use App\Ordering\Domain\Entity\OrderItem;
use App\Ordering\Domain\ValueObject\OrderId;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler(bus: 'query.bus')]
final readonly class GetOrderDiagnosticsHandler
{
    public function __construct(
        private OrderDataProviderInterface $orderDataProvider,
        private OrderReservationStatusProviderInterface $reservationStatusProvider,
        private OrderNotificationStatusProviderInterface $notificationStatusProvider,
    ) {
    }

    public function __invoke(GetOrderDiagnosticsQuery $query): GetOrderDiagnosticsQueryResult
    {
        $orderId = new OrderId($query->orderId);
        $order = $this->orderDataProvider->findById($orderId);

        $items = array_map(
            static fn (OrderItem $item): OrderDiagnosticItemResult => new OrderDiagnosticItemResult(
                productId: $item->productId()->value,
                quantity: $item->quantity(),
                productName: $item->productName(),
                priceAmount: $item->priceAmount(),
            ),
            $order->items()->all(),
        );

        $orderResult = new OrderDiagnosticResult(
            id: $order->id()->value,
            buyerId: $order->buyerId()->value,
            status: $order->status()->value,
            items: $items,
            createdAt: $order->createdAt()->format('Y-m-d\TH:i:s.u\Z'),
            updatedAt: $order->updatedAt()->format('Y-m-d\TH:i:s.u\Z'),
            totalAmount: $order->totalAmount(),
            currency: $order->currency()?->value,
            rejectionReason: $order->rejectionReason(),
        );

        $reservationStatus = $this->reservationStatusProvider->getReservationStatus($query->orderId);
        $inventoryResult = new OrderInventoryDiagnosticResult(
            status: $reservationStatus->status,
            rejectionReason: $reservationStatus->rejectionReason,
        );

        $notifications = $this->notificationStatusProvider->getNotificationsForOrder($query->orderId);
        $notificationsResult = array_map(
            static fn (OrderNotificationStatus $n): OrderNotificationDiagnosticResult => new OrderNotificationDiagnosticResult(
                id: $n->id,
                type: $n->type,
                status: $n->status,
                attempts: $n->attempts,
                errorMessage: $n->errorMessage,
                createdAt: $n->createdAt,
                updatedAt: $n->updatedAt,
                sentAt: $n->sentAt,
            ),
            $notifications,
        );

        return new GetOrderDiagnosticsQueryResult(
            order: $orderResult,
            inventory: $inventoryResult,
            notifications: $notificationsResult,
        );
    }
}
