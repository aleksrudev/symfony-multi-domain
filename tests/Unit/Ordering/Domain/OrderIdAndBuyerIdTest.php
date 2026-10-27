<?php

declare(strict_types=1);

namespace Tests\Unit\Ordering\Domain;

use App\Ordering\Domain\ValueObject\BuyerId;
use App\Ordering\Domain\ValueObject\OrderId;
use App\Ordering\Domain\ValueObject\ProductId;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(OrderId::class)]
#[CoversClass(BuyerId::class)]
#[CoversClass(ProductId::class)]
final class OrderIdAndBuyerIdTest extends TestCase
{
    public function testOrderIdValidAndEquality(): void
    {
        $id1 = new OrderId('d9e7a184-5d5b-11ea-a62a-3499710062d0');
        $id2 = new OrderId('D9E7A184-5D5B-11EA-A62A-3499710062D0');
        $id3 = new OrderId('d9e7a184-5d5b-11ea-a62a-3499710062d1');

        self::assertSame('d9e7a184-5d5b-11ea-a62a-3499710062d0', $id1->value);
        self::assertSame('d9e7a184-5d5b-11ea-a62a-3499710062d0', (string) $id1);
        self::assertTrue($id1->equals($id2));
        self::assertFalse($id1->equals($id3));

        $generated = new OrderId();
        self::assertNotEmpty($generated->value);
        self::assertNotSame($generated->value, new OrderId()->value);
    }

    public function testEmptyOrderIdThrowsException(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new OrderId('   ');
    }

    public function testInvalidUuidOrderIdThrowsException(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new OrderId('not-a-valid-uuid');
    }

    public function testBuyerIdValidAndEquality(): void
    {
        $id1 = new BuyerId('buyer-1');
        $id2 = new BuyerId('buyer-1');
        $id3 = new BuyerId('buyer-2');

        self::assertSame('buyer-1', $id1->value);
        self::assertSame('buyer-1', (string) $id1);
        self::assertTrue($id1->equals($id2));
        self::assertFalse($id1->equals($id3));
    }

    public function testEmptyBuyerIdThrowsException(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new BuyerId('');
    }

    public function testProductIdValidAndEquality(): void
    {
        $id1 = new ProductId('prod-1');
        $id2 = new ProductId('prod-1');
        $id3 = new ProductId('prod-2');

        self::assertSame('prod-1', $id1->value);
        self::assertSame('prod-1', (string) $id1);
        self::assertTrue($id1->equals($id2));
        self::assertFalse($id1->equals($id3));
    }

    public function testEmptyProductIdThrowsException(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new ProductId('');
    }
}
