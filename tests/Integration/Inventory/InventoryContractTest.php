<?php

declare(strict_types=1);

namespace Tests\Integration\Inventory;

use App\Inventory\Domain\DataProvider\StockPositionDataProviderInterface;
use App\Inventory\Domain\Entity\StockPosition;
use App\Inventory\Domain\Repository\StockPositionRepositoryInterface;
use App\Inventory\Domain\ValueObject\ProductId;
use App\Inventory\Infrastructure\Messaging\Inbox\ReleaseStock\ReleaseStockInboxHandler;
use App\Inventory\Infrastructure\Messaging\Inbox\ReleaseStock\ReleaseStockInboxMessage;
use App\Inventory\Infrastructure\Messaging\Inbox\ReserveStock\ReserveStockInboxHandler;
use App\Inventory\Infrastructure\Messaging\Inbox\ReserveStock\ReserveStockInboxItem;
use App\Inventory\Infrastructure\Messaging\Inbox\ReserveStock\ReserveStockInboxMessage;
use App\Inventory\Presentation\Contracts\Dto\ReleaseRequest;
use App\Inventory\Presentation\Contracts\Dto\ReservationItemRequest;
use App\Inventory\Presentation\Contracts\Dto\ReservationRequest;
use App\Inventory\Presentation\Contracts\InventoryContractInterface;
use App\Ordering\Presentation\Contracts\Dto\SubmittedOrderItem;
use App\Ordering\Presentation\Contracts\Event\OrderSubmittedEvent;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Tests\Integration\IntegrationTestCase;

final class InventoryContractTest extends IntegrationTestCase
{
    private InventoryContractInterface $contract;
    private StockPositionRepositoryInterface $stockRepo;
    private StockPositionDataProviderInterface $stockProvider;
    private ReserveStockInboxHandler $reserveInboxHandler;
    private ReleaseStockInboxHandler $releaseInboxHandler;

    protected function setUp(): void
    {
        parent::setUp();
        $container = self::getContainer();
        $this->contract = $container->get(InventoryContractInterface::class);
        $this->stockRepo = $container->get(StockPositionRepositoryInterface::class);
        $this->stockProvider = $container->get(StockPositionDataProviderInterface::class);
        $this->reserveInboxHandler = $container->get(ReserveStockInboxHandler::class);
        $this->releaseInboxHandler = $container->get(ReleaseStockInboxHandler::class);

        $conn = $this->em->getConnection();
        $conn->executeStatement("DELETE FROM inventory.reservation_items WHERE order_id LIKE '00000000-0000-4000-b000-%'");
        $conn->executeStatement("DELETE FROM inventory.reservations WHERE order_id LIKE '00000000-0000-4000-b000-%'");
        $conn->executeStatement("DELETE FROM inventory.stock_positions WHERE product_id LIKE 'test-contract-%'");
    }

    public function testGetReservationResultReturnsNotFoundInitially(): void
    {
        $orderId = '00000000-0000-4000-b000-000000000001';
        $result = $this->contract->getReservationResult($orderId);

        self::assertSame($orderId, $result->orderId);
        self::assertSame('not_found', $result->status);
        self::assertNull($result->rejectionReason);
        self::assertCount(0, $result->items);
    }

    public function testAsyncReservationLifecycleAndSafeRepeat(): void
    {
        $prodA = new ProductId('test-contract-a');
        $this->stockRepo->save(new StockPosition($prodA, onHand: 10, reserved: 0));
        $this->em->flush();
        $this->em->clear();

        $orderId = '00000000-0000-4000-b000-000000000002';

        // 1. Worker executes ReserveStockInboxHandler
        $inboxMessage = new ReserveStockInboxMessage(
            orderId: $orderId,
            items: [
                new ReserveStockInboxItem($prodA->value, 3),
            ],
        );
        ($this->reserveInboxHandler)($inboxMessage);
        $this->em->flush();
        $this->em->clear();

        // Now stock is reserved
        $posAfter = $this->stockProvider->findByProductId($prodA);
        self::assertSame(3, $posAfter->reserved());
        self::assertSame(7, $posAfter->available());

        $statusReserved = $this->contract->getReservationResult($orderId);
        self::assertSame('reserved', $statusReserved->status);
        self::assertCount(1, $statusReserved->items);
        self::assertSame(3, $statusReserved->items[0]->quantity);

        // 2. Redelivery of the inbox message is safe and idempotent
        ($this->reserveInboxHandler)($inboxMessage);
        $this->em->flush();
        $this->em->clear();

        $posAfterRepeat = $this->stockProvider->findByProductId($prodA);
        self::assertSame(3, $posAfterRepeat->reserved()); // Not double-reserved!

        // 3. Release via inbox message
        $releaseInboxMessage = new ReleaseStockInboxMessage($orderId);
        ($this->releaseInboxHandler)($releaseInboxMessage);
        $this->em->flush();
        $this->em->clear();

        $posAfterRelease = $this->stockProvider->findByProductId($prodA);
        self::assertSame(0, $posAfterRelease->reserved());
        self::assertSame(10, $posAfterRelease->available());

        $statusReleased = $this->contract->getReservationResult($orderId);
        self::assertSame('released', $statusReleased->status);
    }

    public function testOrderSubmittedEventTriggersReservationViaInbox(): void
    {
        $prodA = new ProductId('test-contract-event-a');
        $this->stockRepo->save(new StockPosition($prodA, onHand: 15, reserved: 0));
        $this->em->flush();
        $this->em->clear();

        $orderId = '00000000-0000-4000-b000-000000000003';
        $event = new OrderSubmittedEvent(
            eventId: '00000000-0000-7000-8000-000000000001',
            eventAt: '2026-10-23T10:00:00.000000Z',
            type: 'ordering.order_submitted',
            source: 'ordering',
            schemaVersion: 1,
            orderId: $orderId,
            buyerId: 'buyer-1',
            items: [
                new SubmittedOrderItem($prodA->value, 5, 'Product A', 10000),
            ],
            totalAmount: 50000,
            currency: 'RUB',
        );

        /** @var EventDispatcherInterface $dispatcher */
        $dispatcher = self::getContainer()->get(EventDispatcherInterface::class);
        $dispatcher->dispatch($event);

        // Before inbox handler execution, stock is not modified synchronously
        $posBefore = $this->stockProvider->findByProductId($prodA);
        self::assertSame(0, $posBefore->reserved());

        // Process inbox message
        $inboxMessage = new ReserveStockInboxMessage(
            orderId: $orderId,
            items: [
                new ReserveStockInboxItem($prodA->value, 5),
            ],
        );
        ($this->reserveInboxHandler)($inboxMessage);
        $this->em->flush();
        $this->em->clear();

        $posAfter = $this->stockProvider->findByProductId($prodA);
        self::assertSame(5, $posAfter->reserved());
        self::assertSame(10, $posAfter->available());

        $reservationResult = $this->contract->getReservationResult($orderId);
        self::assertSame('reserved', $reservationResult->status);
    }
}
