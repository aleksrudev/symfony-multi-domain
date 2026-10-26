<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure;

use App\Shared\Infrastructure\Messaging\IntegrationEvent\OutboxIntegrationEventDispatcher;
use App\Shared\Infrastructure\Messaging\Outbox\Writer\DoctrineOutboxWriter;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Tests\Fixtures\Modules\Alpha\Presentation\Contracts\Event\ProbePublishedEvent;

final class IntegrationEventDispatcherTest extends TestCase
{
    private Connection $db;
    private OutboxIntegrationEventDispatcher $dispatcher;

    protected function setUp(): void
    {
        $this->db = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $this->db->executeStatement("ATTACH DATABASE ':memory:' AS shared");
        $this->db->executeStatement('CREATE TABLE shared.outbox_messages (id INTEGER PRIMARY KEY, body TEXT NOT NULL, created_at TEXT NOT NULL, available_at TEXT NOT NULL)');
        $this->dispatcher = new OutboxIntegrationEventDispatcher(new DoctrineOutboxWriter($this->db, new MockClock()));
    }

    protected function tearDown(): void
    {
        $this->db->close();
    }

    public function testValidEventIsSnapshottedInsideTheCallerTransaction(): void
    {
        $event = new ProbePublishedEvent(7, [], 'id-1', '2026-10-26T09:00:00.123456Z');
        $this->db->beginTransaction();
        $this->dispatcher->dispatch($event);
        self::assertEquals($event, unserialize(base64_decode($this->db->fetchOne('SELECT body FROM shared.outbox_messages'), true)));
        $this->db->rollBack();
        self::assertSame(0, $this->db->fetchOne('SELECT COUNT(*) FROM shared.outbox_messages'));
    }

    public function testPublicationOutsideTransactionIsRejected(): void
    {
        $this->expectException(\LogicException::class);
        $this->dispatcher->dispatch(new ProbePublishedEvent(7, [], 'id-1', '2026-10-26T09:00:00.123456Z'));
    }

    /** @param array<string, int|string> $changes */
    #[DataProvider('invalidMetadata')]
    public function testInvalidMetadataNeverEntersOutbox(array $changes): void
    {
        $values = array_replace(['eventId' => 'id-1', 'eventAt' => '2026-10-26T09:00:00.123456Z', 'source' => 'Alpha', 'type' => 'alpha.published', 'schemaVersion' => 1], $changes);
        $this->db->beginTransaction();
        try {
            $this->dispatcher->dispatch(new ProbePublishedEvent(7, [], ...$values));
            self::fail('Invalid event was accepted.');
        } catch (\InvalidArgumentException) {
            self::assertSame(0, $this->db->fetchOne('SELECT COUNT(*) FROM shared.outbox_messages'));
            self::assertTrue($this->db->isTransactionActive());
        } finally {
            $this->db->rollBack();
        }
    }

    /** @return iterable<string, array{array<string, int|string>}> */
    public static function invalidMetadata(): iterable
    {
        foreach (['eventId', 'source', 'type'] as $field) {
            foreach (['', str_repeat('x', 191), "a\0b", "\xFF"] as $value) {
                yield $field.'-'.bin2hex(substr($value, 0, 2)).'-'.strlen($value) => [[$field => $value]];
            }
        }
        yield 'version' => [['schemaVersion' => 0]];
        foreach (["2026-10-26T09:00:00.123456Z\0", '', '2026-02-30T09:00:00.123456Z', '2026-10-26T09:00:00Z', '2026-10-26T09:00:00.123456+00:00', '2026-10-26T25:00:00.123456Z'] as $date) {
            yield 'date-'.$date => [['eventAt' => $date]];
        }
    }
}
