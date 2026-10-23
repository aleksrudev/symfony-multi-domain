<?php

declare(strict_types=1);

namespace Tests\Unit\Inventory\Domain;

use App\Inventory\Domain\Entity\StockPosition;
use App\Inventory\Domain\Exception\StockOnHandBelowReservedException;
use App\Inventory\Domain\ValueObject\ProductId;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(StockPosition::class)]
final class StockPositionTest extends TestCase
{
    public function testCreateValidStockPosition(): void
    {
        $productId = new ProductId('prod-1');
        $position = new StockPosition($productId, 10, 2);

        self::assertSame('prod-1', $position->productId()->value);
        self::assertSame(10, $position->onHand());
        self::assertSame(2, $position->reserved());
        self::assertSame(8, $position->available());
        self::assertInstanceOf(\DateTimeImmutable::class, $position->createdAt());
        self::assertInstanceOf(\DateTimeImmutable::class, $position->updatedAt());
    }

    public function testAvailableZeroWhenOnHandEqualsReserved(): void
    {
        $position = new StockPosition(new ProductId('prod-1'), 5, 5);

        self::assertSame(0, $position->available());
    }

    public function testConstructorThrowsOnNegativeOnHand(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('On-hand stock must be non-negative.');

        new StockPosition(new ProductId('prod-1'), -1, 0);
    }

    public function testConstructorThrowsOnNegativeReserved(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Reserved stock must be non-negative.');

        new StockPosition(new ProductId('prod-1'), 10, -1);
    }

    public function testConstructorThrowsWhenOnHandBelowReserved(): void
    {
        $this->expectException(StockOnHandBelowReservedException::class);

        new StockPosition(new ProductId('prod-1'), 2, 5);
    }

    public function testChangeOnHandSuccess(): void
    {
        $position = new StockPosition(new ProductId('prod-1'), 10, 2);
        $initialUpdatedAt = $position->updatedAt();

        // Small sleep to ensure updated_at timestamp changes if precision allows
        usleep(1000);

        $position->changeOnHand(15);

        self::assertSame(15, $position->onHand());
        self::assertSame(2, $position->reserved());
        self::assertSame(13, $position->available());
        self::assertGreaterThanOrEqual($initialUpdatedAt, $position->updatedAt());
    }

    public function testChangeOnHandToEqualReserved(): void
    {
        $position = new StockPosition(new ProductId('prod-1'), 10, 3);
        $position->changeOnHand(3);

        self::assertSame(3, $position->onHand());
        self::assertSame(3, $position->reserved());
        self::assertSame(0, $position->available());
    }

    public function testChangeOnHandThrowsOnNegative(): void
    {
        $position = new StockPosition(new ProductId('prod-1'), 10, 2);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('On-hand stock must be non-negative.');

        $position->changeOnHand(-1);
    }

    public function testChangeOnHandThrowsWhenBelowReserved(): void
    {
        $position = new StockPosition(new ProductId('prod-1'), 10, 5);

        $this->expectException(StockOnHandBelowReservedException::class);

        $position->changeOnHand(4);
    }

    public function testReserveSuccess(): void
    {
        $position = new StockPosition(new ProductId('prod-1'), 10, 2);
        $position->reserve(3);

        self::assertSame(10, $position->onHand());
        self::assertSame(5, $position->reserved());
        self::assertSame(5, $position->available());
    }

    public function testReserveThrowsOnZeroOrNegativeQuantity(): void
    {
        $position = new StockPosition(new ProductId('prod-1'), 10, 2);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Quantity to reserve must be positive.');

        $position->reserve(0);
    }

    public function testReserveThrowsOnInsufficientStock(): void
    {
        $position = new StockPosition(new ProductId('prod-1'), 10, 8);

        $this->expectException(\App\Inventory\Domain\Exception\InsufficientStockException::class);

        $position->reserve(3);
    }

    public function testReleaseSuccess(): void
    {
        $position = new StockPosition(new ProductId('prod-1'), 10, 5);
        $position->release(3);

        self::assertSame(10, $position->onHand());
        self::assertSame(2, $position->reserved());
        self::assertSame(8, $position->available());
    }

    public function testReleaseExceedingReservedCapsAtZero(): void
    {
        $position = new StockPosition(new ProductId('prod-1'), 10, 2);
        $position->release(5);

        self::assertSame(10, $position->onHand());
        self::assertSame(0, $position->reserved());
        self::assertSame(10, $position->available());
    }

    public function testReleaseThrowsOnZeroOrNegativeQuantity(): void
    {
        $position = new StockPosition(new ProductId('prod-1'), 10, 2);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Quantity to release must be positive.');

        $position->release(-1);
    }
}
