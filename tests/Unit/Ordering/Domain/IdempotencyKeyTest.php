<?php

declare(strict_types=1);

namespace Tests\Unit\Ordering\Domain;

use App\Ordering\Domain\Entity\IdempotencyKey;
use App\Ordering\Domain\Enum\IdempotencyOperation;
use App\Ordering\Domain\Exception\IdempotencyConflictException;
use App\Ordering\Domain\ValueObject\BuyerId;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(IdempotencyKey::class)]
final class IdempotencyKeyTest extends TestCase
{
    public function testIdempotencyKeyProperties(): void
    {
        $buyerId = new BuyerId('buyer-1');
        $key = new IdempotencyKey('idem-key-1', $buyerId, IdempotencyOperation::CREATE_DRAFT, 'hash-123', 'payload');

        self::assertSame('idem-key-1', $key->key());
        self::assertTrue($key->buyerId()->equals($buyerId));
        self::assertSame(IdempotencyOperation::CREATE_DRAFT, $key->operation());
        self::assertSame('hash-123', $key->requestHash());
        self::assertSame('payload', $key->responsePayload());
        self::assertInstanceOf(\DateTimeImmutable::class, $key->createdAt());
    }

    public function testAssertMatchesPassesWhenHashMatches(): void
    {
        $key = new IdempotencyKey('idem-key-1', new BuyerId('buyer-1'), IdempotencyOperation::CREATE_DRAFT, 'hash-123');

        $this->expectNotToPerformAssertions();
        $key->assertMatches('hash-123');
    }

    public function testAssertMatchesThrowsConflictExceptionWhenHashDiffers(): void
    {
        $key = new IdempotencyKey('idem-key-1', new BuyerId('buyer-1'), IdempotencyOperation::CREATE_DRAFT, 'hash-123');

        $this->expectException(IdempotencyConflictException::class);
        $key->assertMatches('different-hash');
    }
}
