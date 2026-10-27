<?php

declare(strict_types=1);

namespace Tests\Integration\Ordering;

use App\Catalog\Infrastructure\Persistence\Doctrine\Fixtures\CatalogFixtures;
use App\Inventory\Application\Command\ReserveStock\ReservationItemInput;
use App\Inventory\Application\Command\ReserveStock\ReserveStockCommand;
use App\Inventory\Domain\Entity\StockPosition;
use App\Inventory\Domain\Repository\StockPositionRepositoryInterface;
use App\Inventory\Domain\ValueObject\ProductId as InventoryProductId;
use App\Notifications\Presentation\Contracts\Dto\EnqueueNotificationInput;
use App\Notifications\Presentation\Contracts\NotificationsContractInterface;
use App\Ordering\Application\Command\ConfirmOrder\ConfirmOrderCommand;
use App\Ordering\Application\Query\GetOrderDiagnostics\GetOrderDiagnosticsQuery;
use App\Ordering\Application\Query\GetOrderDiagnostics\GetOrderDiagnosticsQueryResult;
use App\Ordering\Domain\Entity\Order;
use App\Ordering\Domain\Enum\Currency;
use App\Ordering\Domain\Enum\OrderStatus;
use App\Ordering\Domain\Repository\OrderRepositoryInterface;
use App\Ordering\Domain\ValueObject\BuyerId;
use App\Ordering\Domain\ValueObject\OrderId;
use App\Shared\Application\Bus\Command\CommandBusInterface;
use App\Shared\Application\Bus\Query\QueryBusInterface;
use Tests\Integration\IntegrationTestCase;

final class OrderDiagnosticsIntegrationTest extends IntegrationTestCase
{
    private CommandBusInterface $commandBus;
    private QueryBusInterface $queryBus;
    private OrderRepositoryInterface $orderRepo;
    private StockPositionRepositoryInterface $stockRepo;
    private NotificationsContractInterface $notificationsContract;

    protected function setUp(): void
    {
        parent::setUp();
        $container = self::getContainer();
        $this->commandBus = $container->get(CommandBusInterface::class);
        $this->queryBus = $container->get(QueryBusInterface::class);
        $this->orderRepo = $container->get(OrderRepositoryInterface::class);
        $this->stockRepo = $container->get(StockPositionRepositoryInterface::class);
        $this->notificationsContract = $container->get(NotificationsContractInterface::class);

        $conn = $this->em->getConnection();
        $conn->executeStatement("DELETE FROM ordering.order_items WHERE order_id LIKE '00000000-0000-4000-8950-%'");
        $conn->executeStatement("DELETE FROM ordering.orders WHERE id LIKE '00000000-0000-4000-8950-%'");
        $conn->executeStatement("DELETE FROM inventory.reservation_items WHERE order_id LIKE '00000000-0000-4000-8950-%'");
        $conn->executeStatement("DELETE FROM inventory.reservations WHERE order_id LIKE '00000000-0000-4000-8950-%'");
        $conn->executeStatement("DELETE FROM notifications.notifications WHERE order_id LIKE '00000000-0000-4000-8950-%'");
    }

    public function testDiagnosticsAggregatesOrderStockReservationAndNotifications(): void
    {
        $orderIdStr = '00000000-0000-4000-8950-000000000001';
        $buyerIdStr = 'buyer-diag-1';
        $prodIdStr = CatalogFixtures::PRODUCT_KEYBOARD;

        // 1. Stock position in inventory
        $this->stockRepo->save(new StockPosition(new InventoryProductId($prodIdStr), onHand: 10, reserved: 0));
        $this->em->flush();

        // 2. Reserve stock in inventory for this order
        $this->commandBus->dispatch(new ReserveStockCommand(
            orderId: $orderIdStr,
            items: [
                new ReservationItemInput(productId: $prodIdStr, quantity: 2),
            ],
        ));
        $this->em->flush();

        // 3. Create confirmed order in ordering
        $order = new Order(
            new OrderId($orderIdStr),
            new BuyerId($buyerIdStr),
            OrderStatus::PENDING_STOCK,
            totalAmount: 12000,
            currency: Currency::RUB,
        );
        $this->orderRepo->save($order);
        $this->em->flush();

        $this->commandBus->dispatch(new ConfirmOrderCommand($orderIdStr));
        $this->em->flush();
        $this->em->clear();

        // 4. Enqueue notification in notifications
        $this->notificationsContract->enqueueNotification(new EnqueueNotificationInput(
            orderId: $orderIdStr,
            type: 'order_confirmed',
            buyerId: $buyerIdStr,
            payload: ['totalAmount' => 12000],
        ));
        $this->em->flush();
        $this->em->clear();

        // 5. Query diagnostics on query.bus
        /** @var GetOrderDiagnosticsQueryResult $result */
        $result = $this->queryBus->dispatch(new GetOrderDiagnosticsQuery($orderIdStr));

        // 6. Verify aggregated result
        self::assertSame($orderIdStr, $result->order->id);
        self::assertSame($buyerIdStr, $result->order->buyerId);
        self::assertSame('confirmed', $result->order->status);
        self::assertSame(12000, $result->order->totalAmount);
        self::assertSame('RUB', $result->order->currency);

        self::assertSame('reserved', $result->inventory->status);
        self::assertNull($result->inventory->rejectionReason);

        self::assertCount(1, $result->notifications);
        self::assertSame('order_confirmed', $result->notifications[0]->type);
        self::assertSame('sent', $result->notifications[0]->status);
    }
}
