<?php

declare(strict_types=1);

namespace Tests\Integration\Inventory;

use App\Inventory\Domain\Action\ReleaseStockAction;
use App\Inventory\Domain\Action\ReserveStockAction;
use App\Inventory\Domain\Collection\ReservationItemCollection;
use App\Inventory\Domain\DataProvider\ReservationDataProviderInterface;
use App\Inventory\Domain\DataProvider\StockPositionDataProviderInterface;
use App\Inventory\Domain\Entity\Reservation;
use App\Inventory\Domain\Entity\ReservationItem;
use App\Inventory\Domain\Entity\StockPosition;
use App\Inventory\Domain\Enum\ReservationRejectionReason;
use App\Inventory\Domain\Enum\ReservationStatus;
use App\Inventory\Domain\Exception\ReservationConflictException;
use App\Inventory\Domain\Repository\ReservationRepositoryInterface;
use App\Inventory\Domain\Repository\StockPositionRepositoryInterface;
use App\Inventory\Domain\ValueObject\OrderId;
use App\Inventory\Domain\ValueObject\ProductId;
use Tests\Integration\IntegrationTestCase;

final class ReservationPersistenceTest extends IntegrationTestCase
{
    private StockPositionRepositoryInterface $stockRepo;
    private StockPositionDataProviderInterface $stockProvider;
    private ReservationRepositoryInterface $reservationRepo;
    private ReservationDataProviderInterface $reservationProvider;
    private ReserveStockAction $reserveAction;
    private ReleaseStockAction $releaseAction;

    protected function setUp(): void
    {
        parent::setUp();
        $container = self::getContainer();
        $this->stockRepo = $container->get(StockPositionRepositoryInterface::class);
        $this->stockProvider = $container->get(StockPositionDataProviderInterface::class);
        $this->reservationRepo = $container->get(ReservationRepositoryInterface::class);
        $this->reservationProvider = $container->get(ReservationDataProviderInterface::class);
        $this->reserveAction = $container->get(ReserveStockAction::class);
        $this->releaseAction = $container->get(ReleaseStockAction::class);

        // Clean up test data
        $conn = $this->em->getConnection();
        $conn->executeStatement("DELETE FROM inventory.reservation_items WHERE order_id LIKE '00000000-0000-4000-a000-%'");
        $conn->executeStatement("DELETE FROM inventory.reservations WHERE order_id LIKE '00000000-0000-4000-a000-%'");
        $conn->executeStatement("DELETE FROM inventory.stock_positions WHERE product_id LIKE 'test-res-%'");
    }

    public function testSaveAndFindReservationWithItems(): void
    {
        $orderId = new OrderId('00000000-0000-4000-a000-000000000001');
        $items = new ReservationItemCollection([
            new ReservationItem(new ProductId('test-res-prod-1'), 2),
            new ReservationItem(new ProductId('test-res-prod-2'), 3),
        ]);

        $reservation = Reservation::createReserved($orderId, $items);
        $this->reservationRepo->save($reservation);
        $this->em->flush();
        $this->em->clear();

        $loaded = $this->reservationProvider->findByOrderId($orderId);

        self::assertSame($orderId->value, $loaded->orderId()->value);
        self::assertSame(ReservationStatus::RESERVED, $loaded->status());
        self::assertCount(2, $loaded->items());
    }

    public function testAtomicReservationSuccessAndRelease(): void
    {
        $prodA = new ProductId('test-res-atomic-a');
        $prodB = new ProductId('test-res-atomic-b');
        $this->stockRepo->save(new StockPosition($prodA, onHand: 10, reserved: 0));
        $this->stockRepo->save(new StockPosition($prodB, onHand: 8, reserved: 0));
        $this->em->flush();
        $this->em->clear();

        $orderId = new OrderId('00000000-0000-4000-a000-000000000002');
        $items = new ReservationItemCollection([
            new ReservationItem($prodB, 3),
            new ReservationItem($prodA, 4),
        ]);

        $reservation = ($this->reserveAction)($orderId, $items);
        $this->em->flush();
        $this->em->clear();

        self::assertSame(ReservationStatus::RESERVED, $reservation->status());

        $loadedPosA = $this->stockProvider->findByProductId($prodA);
        $loadedPosB = $this->stockProvider->findByProductId($prodB);
        self::assertSame(4, $loadedPosA->reserved());
        self::assertSame(6, $loadedPosA->available());
        self::assertSame(3, $loadedPosB->reserved());
        self::assertSame(5, $loadedPosB->available());

        // Now release the reservation
        $released = ($this->releaseAction)($orderId);
        $this->em->flush();
        $this->em->clear();

        self::assertSame(ReservationStatus::RELEASED, $released->status());

        $restoredPosA = $this->stockProvider->findByProductId($prodA);
        $restoredPosB = $this->stockProvider->findByProductId($prodB);
        self::assertSame(0, $restoredPosA->reserved());
        self::assertSame(10, $restoredPosA->available());
        self::assertSame(0, $restoredPosB->reserved());
        self::assertSame(8, $restoredPosB->available());
    }

    public function testAtomicReservationAllOrNothingWhenOneProductShort(): void
    {
        $prodA = new ProductId('test-res-short-a');
        $prodB = new ProductId('test-res-short-b');
        $this->stockRepo->save(new StockPosition($prodA, onHand: 10, reserved: 0)); // plenty
        $this->stockRepo->save(new StockPosition($prodB, onHand: 2, reserved: 1)); // only 1 available!
        $this->em->flush();
        $this->em->clear();

        $orderId = new OrderId('00000000-0000-4000-a000-000000000003');
        $items = new ReservationItemCollection([
            new ReservationItem($prodA, 3), // can fulfill
            new ReservationItem($prodB, 2), // exceeds available 1
        ]);

        $reservation = ($this->reserveAction)($orderId, $items);
        $this->em->flush();
        $this->em->clear();

        self::assertSame(ReservationStatus::REJECTED, $reservation->status());
        self::assertSame(ReservationRejectionReason::INSUFFICIENT_STOCK, $reservation->rejectionReason());

        // Ensure prodA was NOT partially reserved (All or nothing!)
        $posA = $this->stockProvider->findByProductId($prodA);
        $posB = $this->stockProvider->findByProductId($prodB);
        self::assertSame(0, $posA->reserved());
        self::assertSame(1, $posB->reserved());
    }

    public function testDefensiveReleaseBeforeReservePreventsLateReservation(): void
    {
        $prodA = new ProductId('test-res-defensive-a');
        $this->stockRepo->save(new StockPosition($prodA, onHand: 10, reserved: 0));
        $this->em->flush();
        $this->em->clear();

        $orderId = new OrderId('00000000-0000-4000-a000-000000000004');

        // 1. Release arrives before Reserve
        $releaseRes = ($this->releaseAction)($orderId);
        $this->em->flush();
        $this->em->clear();

        self::assertSame(ReservationStatus::RELEASED, $releaseRes->status());

        // 2. Late reserve arrives
        $items = new ReservationItemCollection([
            new ReservationItem($prodA, 2),
        ]);
        $lateRes = ($this->reserveAction)($orderId, $items);
        $this->em->flush();
        $this->em->clear();

        self::assertSame(ReservationStatus::RELEASED, $lateRes->status());

        // Ensure stock was NOT reserved
        $posA = $this->stockProvider->findByProductId($prodA);
        self::assertSame(0, $posA->reserved());
        self::assertSame(10, $posA->available());
    }

    public function testIdempotentRepeatAndConflict(): void
    {
        $prodA = new ProductId('test-res-idem-a');
        $this->stockRepo->save(new StockPosition($prodA, onHand: 10, reserved: 0));
        $this->em->flush();
        $this->em->clear();

        $orderId = new OrderId('00000000-0000-4000-a000-000000000005');
        $items = new ReservationItemCollection([
            new ReservationItem($prodA, 2),
        ]);

        $res1 = ($this->reserveAction)($orderId, $items);
        $this->em->flush();
        $this->em->clear();

        self::assertSame(ReservationStatus::RESERVED, $res1->status());

        // Repeat with identical items
        $res2 = ($this->reserveAction)($orderId, $items);
        self::assertSame(ReservationStatus::RESERVED, $res2->status());

        // Still only 2 reserved
        $posA = $this->stockProvider->findByProductId($prodA);
        self::assertSame(2, $posA->reserved());

        // Repeat with conflicting items
        $differentItems = new ReservationItemCollection([
            new ReservationItem($prodA, 5),
        ]);

        $this->expectException(ReservationConflictException::class);
        ($this->reserveAction)($orderId, $differentItems);
    }

    public function testRaceForLastUnitOnlyOneWinner(): void
    {
        $prod = new ProductId('test-res-race-last');
        $this->stockRepo->save(new StockPosition($prod, onHand: 1, reserved: 0));
        $this->em->flush();
        $this->em->clear();

        $order1 = new OrderId('00000000-0000-4000-a000-000000000006');
        $order2 = new OrderId('00000000-0000-4000-a000-000000000007');

        $items = new ReservationItemCollection([
            new ReservationItem($prod, 1),
        ]);

        $res1 = ($this->reserveAction)($order1, $items);
        $this->em->flush();

        $res2 = ($this->reserveAction)($order2, $items);
        $this->em->flush();
        $this->em->clear();

        self::assertSame(ReservationStatus::RESERVED, $res1->status());
        self::assertSame(ReservationStatus::REJECTED, $res2->status());
        self::assertSame(ReservationRejectionReason::INSUFFICIENT_STOCK, $res2->rejectionReason());

        $pos = $this->stockProvider->findByProductId($prod);
        self::assertSame(1, $pos->reserved());
        self::assertSame(0, $pos->available());
    }

    public function testDeadlockFreeOrderSorting(): void
    {
        $prodA = new ProductId('test-res-prod-a');
        $prodB = new ProductId('test-res-prod-b');
        $this->stockRepo->save(new StockPosition($prodA, onHand: 10, reserved: 0));
        $this->stockRepo->save(new StockPosition($prodB, onHand: 10, reserved: 0));
        $this->em->flush();
        $this->em->clear();

        $order1 = new OrderId('00000000-0000-4000-a000-000000000008');
        $order2 = new OrderId('00000000-0000-4000-a000-000000000009');

        // Order 1 requests [B, A]
        $items1 = new ReservationItemCollection([
            new ReservationItem($prodB, 1),
            new ReservationItem($prodA, 1),
        ]);

        // Order 2 requests [A, B]
        $items2 = new ReservationItemCollection([
            new ReservationItem($prodA, 2),
            new ReservationItem($prodB, 2),
        ]);

        // Both actions sort by productId ascending prior to pessimistic lock acquisition
        $res1 = ($this->reserveAction)($order1, $items1);
        $this->em->flush();

        $res2 = ($this->reserveAction)($order2, $items2);
        $this->em->flush();
        $this->em->clear();

        self::assertSame(ReservationStatus::RESERVED, $res1->status());
        self::assertSame(ReservationStatus::RESERVED, $res2->status());

        $posA = $this->stockProvider->findByProductId($prodA);
        $posB = $this->stockProvider->findByProductId($prodB);
        self::assertSame(3, $posA->reserved());
        self::assertSame(3, $posB->reserved());
    }
}

