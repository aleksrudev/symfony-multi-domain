<?php

declare(strict_types=1);

namespace Tests\Unit\Catalog\Domain;

use App\Catalog\Domain\ValueObject\ProductId;
use PHPUnit\Framework\TestCase;

final class ProductIdTest extends TestCase
{
    public function testHoldsValueAndConvertsToString(): void
    {
        $id = new ProductId('prod-123');

        self::assertSame('prod-123', $id->value);
        self::assertSame('prod-123', (string) $id);
    }

    public function testRejectsEmptyString(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Product ID cannot be empty.');

        new ProductId('   ');
    }

    public function testEqualityByValue(): void
    {
        $id1 = new ProductId('prod-abc');
        $id2 = new ProductId('prod-abc');
        $id3 = new ProductId('prod-xyz');

        self::assertTrue($id1->equals($id2));
        self::assertFalse($id1->equals($id3));
    }
}
