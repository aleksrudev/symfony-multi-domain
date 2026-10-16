<?php

declare(strict_types=1);

namespace Tests\Unit\Domain;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Fixtures\ValueObject\OrderId;
use Tests\Fixtures\ValueObject\ProductId;
use Tests\Fixtures\ValueObject\ShipmentId;
use Tests\Fixtures\ValueObject\TrackingId;

final class UidIdTest extends TestCase
{
    public function testGeneratesUuidV4WhenNoValueIsProvided(): void
    {
        $id = new OrderId();

        self::assertMatchesRegularExpression('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/D', $id->value);
        self::assertSame($id->value, (string) $id);
        self::assertNotSame($id->value, new OrderId()->value);
    }

    public function testNormalizesAnExistingUuidWithoutRegeneratingIt(): void
    {
        $id = new OrderId('D9E7A184-5D5B-11EA-A62A-3499710062D0');

        self::assertSame('d9e7a184-5d5b-11ea-a62a-3499710062d0', $id->value);
        self::assertSame($id->value, (string) $id);
    }

    public function testGeneratesUlidWhenNoValueIsProvided(): void
    {
        $id = new ShipmentId();

        self::assertMatchesRegularExpression('/^[0-7][0-9A-HJKMNP-TV-Z]{25}$/D', $id->value);
        self::assertSame($id->value, (string) $id);
        self::assertNotSame($id->value, new ShipmentId()->value);
    }

    public function testNormalizesAnExistingUlidWithoutRegeneratingIt(): void
    {
        $id = new ShipmentId('01arz3ndektsv4rrffq69g5fav');

        self::assertSame('01ARZ3NDEKTSV4RRFFQ69G5FAV', $id->value);
        self::assertSame($id->value, (string) $id);
    }

    #[DataProvider('invalidValues')]
    public function testRejectsInvalidValues(string $idClass, string $value): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new $idClass($value);
    }

    /** @return iterable<string, array{class-string<OrderId|ShipmentId>, string}> */
    public static function invalidValues(): iterable
    {
        foreach (['empty' => '', 'whitespace' => ' ', 'arbitrary' => 'order-1'] as $name => $value) {
            yield 'uuid-'.$name => [OrderId::class, $value];
            yield 'ulid-'.$name => [ShipmentId::class, $value];
        }

        yield 'uuid-invalid-variant' => [OrderId::class, 'd9e7a184-5d5b-41ea-762a-3499710062d0'];
        yield 'ulid-overflow' => [ShipmentId::class, '81ARZ3NDEKTSV4RRFFQ69G5FAV'];
    }

    public function testUuidEqualityRequiresTheSameConcreteTypeAndValue(): void
    {
        $id = new OrderId('d9e7a184-5d5b-11ea-a62a-3499710062d0');

        self::assertTrue($id->equals(new OrderId('D9E7A184-5D5B-11EA-A62A-3499710062D0')));
        self::assertFalse($id->equals(new OrderId('d9e7a184-5d5b-11ea-a62a-3499710062d1')));
        self::assertFalse($id->equals(new ProductId($id->value)));
    }

    public function testUlidEqualityRequiresTheSameConcreteTypeAndValue(): void
    {
        $id = new ShipmentId('01ARZ3NDEKTSV4RRFFQ69G5FAV');

        self::assertTrue($id->equals(new ShipmentId('01arz3ndektsv4rrffq69g5fav')));
        self::assertFalse($id->equals(new ShipmentId('01ARZ3NDEKTSV4RRFFQ69G5FAW')));
        self::assertFalse($id->equals(new TrackingId($id->value)));
    }
}
