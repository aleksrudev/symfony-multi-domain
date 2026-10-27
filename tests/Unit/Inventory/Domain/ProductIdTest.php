<?php

declare(strict_types=1);

namespace Tests\Unit\Inventory\Domain;

use App\Inventory\Domain\ValueObject\ProductId;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ProductId::class)]
final class ProductIdTest extends TestCase
{
    public function testCreatesValidProductId(): void
    {
        $id = new ProductId('prod-123');
        self::assertSame('prod-123', $id->value);
        self::assertSame('prod-123', (string) $id);
    }

    public function testTrimsWhitespace(): void
    {
        $id = new ProductId('  prod-123  ');
        self::assertSame('prod-123', $id->value);
    }

    public function testThrowsOnEmptyString(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Product ID cannot be empty.');
        new ProductId('   ');
    }

    public function testEquals(): void
    {
        $id1 = new ProductId('prod-1');
        $id2 = new ProductId('prod-1');
        $id3 = new ProductId('prod-2');

        self::assertTrue($id1->equals($id2));
        self::assertFalse($id1->equals($id3));
    }
}
