<?php

declare(strict_types=1);

namespace Tests\Unit\Ordering\Application;

use App\Ordering\Application\Query\GetOrderDiagnostics\GetOrderDiagnosticsHandler;
use App\Ordering\Application\Query\GetOrderDiagnostics\GetOrderDiagnosticsQuery;
use App\Ordering\Application\Query\GetOrderDiagnostics\Provider\OrderNotificationStatus;
use App\Ordering\Application\Query\GetOrderDiagnostics\Provider\OrderNotificationStatusProviderInterface;
use App\Ordering\Application\Query\GetOrderDiagnostics\Provider\OrderReservationStatus;
use App\Ordering\Application\Query\GetOrderDiagnostics\Provider\OrderReservationStatusProviderInterface;
use App\Ordering\Domain\Collection\OrderItemCollection;
use App\Ordering\Domain\Collection\ProductSnapshotCollection;
use App\Ordering\Domain\DataProvider\OrderDataProviderInterface;
use App\Ordering\Domain\Entity\Order;
use App\Ordering\Domain\Entity\OrderItem;
use App\Ordering\Domain\Enum\Currency;
use App\Ordering\Domain\Exception\OrderNotFoundException;
use App\Ordering\Domain\ValueObject\BuyerId;
use App\Ordering\Domain\ValueObject\OrderId;
use App\Ordering\Domain\ValueObject\ProductId;
use App\Ordering\Domain\ValueObject\ProductSnapshot;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(GetOrderDiagnosticsHandler::class)]
final class GetOrderDiagnosticsHandlerTest extends TestCase
{
    private const ORDER_ID = '00000000-0000-4000-8000-000000000001';

    public function testReturnsDiagnosticsSuccessfully(): void
    {
        $orderId = new OrderId(self::ORDER_ID);
        $order = Order::createDraft($orderId, new BuyerId('buyer-1'));
        $order->replaceItems(new OrderItemCollection([
            new OrderItem(new ProductId('prod-1'), 2),
        ]));
        $order->submit(new ProductSnapshotCollection([
            new ProductSnapshot(new ProductId('prod-1'), 'Product 1', 10000, Currency::RUB),
        ]));
        $order->confirm();

        $orderProvider = $this->createMock(OrderDataProviderInterface::class);
        $orderProvider->expects(self::once())
            ->method('findById')
            ->with(self::equalTo($orderId))
            ->willReturn($order);

        $reservationProvider = $this->createMock(OrderReservationStatusProviderInterface::class);
        $reservationProvider->expects(self::once())
            ->method('getReservationStatus')
            ->with(self::ORDER_ID)
            ->willReturn(new OrderReservationStatus(
                orderId: self::ORDER_ID,
                status: 'reserved',
                rejectionReason: null,
            ));

        $notificationProvider = $this->createMock(OrderNotificationStatusProviderInterface::class);
        $notificationProvider->expects(self::once())
            ->method('getNotificationsForOrder')
            ->with(self::ORDER_ID)
            ->willReturn([
                new OrderNotificationStatus(
                    id: 'notif-1',
                    type: 'order_confirmed',
                    status: 'sent',
                    attempts: 1,
                    errorMessage: null,
                    createdAt: '2026-10-27T10:00:00.000000Z',
                    updatedAt: '2026-10-27T10:00:01.000000Z',
                    sentAt: '2026-10-27T10:00:01.000000Z',
                ),
            ]);

        $handler = new GetOrderDiagnosticsHandler(
            $orderProvider,
            $reservationProvider,
            $notificationProvider,
        );

        $result = $handler(new GetOrderDiagnosticsQuery(self::ORDER_ID));

        self::assertSame(self::ORDER_ID, $result->order->id);
        self::assertSame('buyer-1', $result->order->buyerId);
        self::assertSame('confirmed', $result->order->status);
        self::assertSame(20000, $result->order->totalAmount);
        self::assertSame('RUB', $result->order->currency);
        self::assertCount(1, $result->order->items);
        self::assertSame('prod-1', $result->order->items[0]->productId);
        self::assertSame(2, $result->order->items[0]->quantity);

        self::assertSame('reserved', $result->inventory->status);
        self::assertNull($result->inventory->rejectionReason);

        self::assertCount(1, $result->notifications);
        self::assertSame('order_confirmed', $result->notifications[0]->type);
        self::assertSame('sent', $result->notifications[0]->status);
        self::assertSame(1, $result->notifications[0]->attempts);

        $array = $result->toArray();
        self::assertArrayHasKey('order', $array);
        self::assertArrayHasKey('inventory', $array);
        self::assertArrayHasKey('notifications', $array);
    }

    public function testThrowsOrderNotFoundExceptionWhenOrderDoesNotExist(): void
    {
        $orderId = new OrderId(self::ORDER_ID);

        $orderProvider = $this->createMock(OrderDataProviderInterface::class);
        $orderProvider->expects(self::once())
            ->method('findById')
            ->with(self::equalTo($orderId))
            ->willThrowException(new OrderNotFoundException($orderId));

        $reservationProvider = $this->createMock(OrderReservationStatusProviderInterface::class);
        $notificationProvider = $this->createMock(OrderNotificationStatusProviderInterface::class);

        $handler = new GetOrderDiagnosticsHandler(
            $orderProvider,
            $reservationProvider,
            $notificationProvider,
        );

        $this->expectException(OrderNotFoundException::class);
        $handler(new GetOrderDiagnosticsQuery(self::ORDER_ID));
    }
}
