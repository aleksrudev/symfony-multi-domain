<?php

declare(strict_types=1);

namespace Tests\Integration\Inventory;

use App\Inventory\Application\Command\ReleaseStock\ReleaseStockCommand;
use App\Inventory\Application\Command\ReserveStock\Input\ReservationItemInput;
use App\Inventory\Application\Command\ReserveStock\ReserveStockCommand;
use App\Inventory\Domain\DataProvider\StockPositionDataProviderInterface;
use App\Inventory\Domain\Entity\StockPosition;
use App\Inventory\Domain\Repository\StockPositionRepositoryInterface;
use App\Inventory\Domain\ValueObject\ProductId;
use App\Inventory\Presentation\Contracts\InventoryContractInterface;
use App\Shared\Application\Bus\Command\CommandBusInterface;
use Tests\Integration\IntegrationTestCase;

final class InventoryContractTest extends IntegrationTestCase
{
    private InventoryContractInterface $contract;
    private CommandBusInterface $commandBus;
    private StockPositionRepositoryInterface $stockRepo;
    private StockPositionDataProviderInterface $stockProvider;

    protected function setUp(): void
    {
        parent::setUp();
        $container = self::getContainer();
        $this->contract = $container->get(InventoryContractInterface::class);
        $this->commandBus = $container->get(CommandBusInterface::class);
        $this->stockRepo = $container->get(StockPositionRepositoryInterface::class);
        $this->stockProvider = $container->get(StockPositionDataProviderInterface::class);

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

    public function testReservationLifecycleReflectedInContract(): void
    {
        $prodA = new ProductId('test-contract-a');
        $this->stockRepo->save(new StockPosition($prodA, onHand: 10, reserved: 0));
        $this->em->flush();
        $this->em->clear();

        $orderId = '00000000-0000-4000-b000-000000000002';

        // 1. Reserve stock via command
        $this->commandBus->dispatch(new ReserveStockCommand(
            orderId: $orderId,
            items: [
                new ReservationItemInput($prodA->value, 3),
            ],
        ));

        // Now stock is reserved
        $posAfter = $this->stockProvider->findByProductId($prodA);
        self::assertSame(3, $posAfter->reserved());
        self::assertSame(7, $posAfter->available());

        $statusReserved = $this->contract->getReservationResult($orderId);
        self::assertSame('reserved', $statusReserved->status);
        self::assertCount(1, $statusReserved->items);
        self::assertSame(3, $statusReserved->items[0]->quantity);

        // 2. Release via command
        $this->commandBus->dispatch(new ReleaseStockCommand($orderId));

        $posAfterRelease = $this->stockProvider->findByProductId($prodA);
        self::assertSame(0, $posAfterRelease->reserved());
        self::assertSame(10, $posAfterRelease->available());

        $statusReleased = $this->contract->getReservationResult($orderId);
        self::assertSame('released', $statusReleased->status);
    }
}
