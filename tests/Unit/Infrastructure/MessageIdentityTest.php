<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure;

use App\Shared\Infrastructure\Messaging\IdempotentConsumer\Identity\MessageIdentity;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class MessageIdentityTest extends TestCase
{
    public function testNamespacesKeepTheSameIdDistinct(): void
    {
        $webhook = new MessageIdentity('provider.webhook', 'delivery-42');
        $import = new MessageIdentity('batch.import', 'delivery-42');
        self::assertSame('provider.webhook', $webhook->namespace);
        self::assertSame('batch.import', $import->namespace);
        self::assertSame('delivery-42', $webhook->id);
        self::assertNotEquals($webhook, $import);
    }

    public function testAccepts190UnicodeCharactersWithoutNormalizingIdentity(): void
    {
        $identity = new MessageIdentity(str_repeat('я', 190), str_repeat('🙂', 190));
        self::assertSame(str_repeat('я', 190), $identity->namespace);
        self::assertSame(str_repeat('🙂', 190), $identity->id);
    }

    #[DataProvider('invalidIdentities')]
    public function testRejectsInvalidIdentities(string $namespace, string $id): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new MessageIdentity($namespace, $id);
    }

    /** @return iterable<string, array{string, string}> */
    public static function invalidIdentities(): iterable
    {
        yield 'empty namespace' => ['', 'delivery-42'];
        yield 'empty id' => ['provider.webhook', ''];
        yield 'long namespace' => [str_repeat('x', 191), 'delivery-42'];
        yield 'long id' => ['provider.webhook', str_repeat('x', 191)];
        yield 'long Unicode namespace' => [str_repeat('я', 191), 'delivery-42'];
        yield 'long Unicode id' => ['provider.webhook', str_repeat('🙂', 191)];
        yield 'NUL namespace' => ["provider\0webhook", 'delivery-42'];
        yield 'NUL id' => ['provider.webhook', "delivery\0id"];
        yield 'invalid UTF-8 namespace' => ["\xFF", 'delivery-42'];
        yield 'invalid UTF-8 id' => ['provider.webhook', "\xFF"];
    }
}
