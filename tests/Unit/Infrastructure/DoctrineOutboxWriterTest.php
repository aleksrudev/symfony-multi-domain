<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure;

use App\Shared\Infrastructure\Messaging\Outbox\Writer\DoctrineOutboxWriter;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Tests\Fixtures\Messaging\NestedMessageValue;
use Tests\Fixtures\Messaging\PlainMessage;

final class DoctrineOutboxWriterTest extends TestCase
{
    private Connection $db;

    protected function setUp(): void
    {
        $this->db = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $this->db->executeStatement("ATTACH DATABASE ':memory:' AS shared");
        $this->db->executeStatement('CREATE TABLE shared.outbox_messages (id INTEGER PRIMARY KEY, body TEXT NOT NULL, created_at TEXT NOT NULL, available_at TEXT NOT NULL)');
    }

    protected function tearDown(): void
    {
        $this->db->close();
    }

    public function testAppendAcceptsPlainObjectWithoutMessageInterfaces(): void
    {
        $writer = new DoctrineOutboxWriter($this->db, new MockClock('2026-10-26T12:00:00.123456+03:00'));
        $message = new PlainMessage('operation-1', new NestedMessageValue('nested-value'), "a\0b");
        $this->db->beginTransaction();
        $writer->append($message);
        $row = $this->db->fetchAssociative('SELECT * FROM shared.outbox_messages');
        self::assertIsArray($row);
        $restored = unserialize(base64_decode($row['body'], true));
        self::assertInstanceOf(PlainMessage::class, $restored);
        self::assertSame('operation-1', $restored->operationId);
        self::assertSame('nested-value', $restored->nested->value);
        self::assertSame("a\0b", $restored->binary);
        self::assertSame('2026-10-26 09:00:00.123456', $row['created_at']);
        self::assertSame($row['created_at'], $row['available_at']);
        self::assertTrue($this->db->isTransactionActive());
        $this->db->rollBack();
        self::assertSame(0, $this->db->fetchOne('SELECT COUNT(*) FROM shared.outbox_messages'));
    }

    public function testAppendRequiresActiveTransaction(): void
    {
        $writer = new DoctrineOutboxWriter($this->db, new MockClock());
        $this->expectException(\LogicException::class);
        $writer->append(new \stdClass());
    }

    public function testSnapshotIsTakenImmediately(): void
    {
        $message = (object) ['value' => 'before'];
        $this->db->beginTransaction();
        new DoctrineOutboxWriter($this->db, new MockClock())->append($message);
        $message->value = 'after';
        $this->db->commit();
        $body = $this->db->fetchOne('SELECT body FROM shared.outbox_messages');
        self::assertSame('before', unserialize(base64_decode($body, true))->value);
    }
}
