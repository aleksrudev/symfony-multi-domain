<?php

declare(strict_types=1);

namespace Tests\Integration\Inventory;

use App\Inventory\Domain\DataProvider\StockPositionDataProviderInterface;
use App\Inventory\Domain\Entity\StockPosition;
use App\Inventory\Domain\Exception\StockPositionNotFoundException;
use App\Inventory\Domain\Repository\StockPositionRepositoryInterface;
use App\Inventory\Domain\ValueObject\ProductId;
use Doctrine\DBAL\Exception\DriverException;
use Tests\Integration\IntegrationTestCase;

final class StockPositionPersistenceTest extends IntegrationTestCase
{
    private StockPositionRepositoryInterface $repository;
    private StockPositionDataProviderInterface $dataProvider;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repository = self::getContainer()->get(StockPositionRepositoryInterface::class);
        $this->dataProvider = self::getContainer()->get(StockPositionDataProviderInterface::class);

        // Clean up test records
        $this->em->getConnection()->executeStatement(
            "DELETE FROM inventory.stock_positions WHERE product_id LIKE 'test-%'",
        );
    }

    public function testSaveAndFindStockPosition(): void
    {
        $productId = new ProductId('test-persist-1');
        $position = new StockPosition($productId, onHand: 25, reserved: 10);

        $this->repository->save($position);
        $this->em->flush();
        $this->em->clear();

        $loaded = $this->dataProvider->findByProductId($productId);

        self::assertSame('test-persist-1', $loaded->productId()->value);
        self::assertSame(25, $loaded->onHand());
        self::assertSame(10, $loaded->reserved());
        self::assertSame(15, $loaded->available());
    }

    public function testPessimisticWriteLockInTransaction(): void
    {
        $productId = new ProductId('test-lock-1');
        $position = new StockPosition($productId, onHand: 50, reserved: 5);

        $this->repository->save($position);
        $this->em->flush();
        $this->em->clear();

        $this->em->beginTransaction();
        try {
            $locked = $this->dataProvider->findByProductId($productId, lock: true);
            self::assertSame('test-lock-1', $locked->productId()->value);
            $locked->changeOnHand(60);
            $this->repository->save($locked);
            $this->em->flush();
            $this->em->commit();
        } catch (\Throwable $e) {
            $this->em->rollback();
            throw $e;
        }

        $this->em->clear();
        $reloaded = $this->dataProvider->findByProductId($productId);
        self::assertSame(60, $reloaded->onHand());
    }

    public function testFindNonExistentThrowsStockPositionNotFoundException(): void
    {
        $this->expectException(StockPositionNotFoundException::class);
        $this->dataProvider->findByProductId(new ProductId('test-non-existent'));
    }

    public function testDatabaseCheckConstraintOnHandGteReserved(): void
    {
        $this->expectException(DriverException::class);

        $this->em->getConnection()->executeStatement(
            "INSERT INTO inventory.stock_positions (product_id, on_hand, reserved, created_at, updated_at)
             VALUES ('test-fail-reserved', 2, 5, NOW(), NOW())",
        );
    }

    public function testDatabaseCheckConstraintOnHandGteZero(): void
    {
        $this->expectException(DriverException::class);

        $this->em->getConnection()->executeStatement(
            "INSERT INTO inventory.stock_positions (product_id, on_hand, reserved, created_at, updated_at)
             VALUES ('test-fail-negative-on-hand', -1, 0, NOW(), NOW())",
        );
    }

    public function testDatabaseCheckConstraintReservedGteZero(): void
    {
        $this->expectException(DriverException::class);

        $this->em->getConnection()->executeStatement(
            "INSERT INTO inventory.stock_positions (product_id, on_hand, reserved, created_at, updated_at)
             VALUES ('test-fail-negative-reserved', 10, -1, NOW(), NOW())",
        );
    }
}

