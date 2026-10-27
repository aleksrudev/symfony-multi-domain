<?php

declare(strict_types=1);

namespace App\Ordering\Application\Query\GetOrderDiagnostics\Result;

final readonly class GetOrderDiagnosticsQueryResult
{
    /**
     * @param list<OrderNotificationDiagnosticResult> $notifications
     */
    public function __construct(
        public OrderDiagnosticResult $order,
        public OrderInventoryDiagnosticResult $inventory,
        public array $notifications,
    ) {
    }

    /**
     * @return array{
     *     order: array{
     *         id: string,
     *         buyerId: string,
     *         status: string,
     *         items: list<array{productId: string, quantity: int, productName: ?string, priceAmount: ?int}>,
     *         totalAmount: ?int,
     *         currency: ?string,
     *         rejectionReason: ?string,
     *         createdAt: string,
     *         updatedAt: string
     *     },
     *     inventory: array{status: string, rejectionReason: ?string},
     *     notifications: list<array{
     *         id: string,
     *         type: string,
     *         status: string,
     *         attempts: int,
     *         errorMessage: ?string,
     *         createdAt: string,
     *         updatedAt: string,
     *         sentAt: ?string
     *     }>
     * }
     */
    public function toArray(): array
    {
        return [
            'order' => $this->order->toArray(),
            'inventory' => $this->inventory->toArray(),
            'notifications' => array_map(
                static fn (OrderNotificationDiagnosticResult $n): array => $n->toArray(),
                $this->notifications,
            ),
        ];
    }
}

