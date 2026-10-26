<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Shared\Infrastructure\Messaging\Outbox\Writer\DoctrineOutboxWriter;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Clock\MockClock;
use Tests\Fixtures\Messaging\NestedMessageValue;
use Tests\Fixtures\Messaging\OutboxStorageTestCase;
use Tests\Fixtures\Messaging\PlainMessage;
use Tests\Fixtures\Messaging\UnserializableMessage;

final class TransactionalOutboxTest extends OutboxStorageTestCase
{
    public function testBusinessWriteAndSnapshotCommitTogether(): void
    {
        $this->db->transactional(function (Connection $db): void {
            $db->insert('public.outbox_test_business', ['operation_id' => 'commit-1']);
            new DoctrineOutboxWriter($db, new MockClock())->append(new PlainMessage('commit-1', new NestedMessageValue('value'), "a\0b"));
        });
        $observer = $this->observer();
        try {
            self::assertSame(1, $observer->fetchOne("SELECT COUNT(*) FROM public.outbox_test_business WHERE operation_id = 'commit-1'"));
            $body = $observer->fetchOne('SELECT body FROM shared.outbox_messages');
            $message = unserialize(base64_decode($body, true));
            self::assertInstanceOf(PlainMessage::class, $message);
            self::assertSame('commit-1', $message->operationId);
            self::assertSame("a\0b", $message->binary);
            self::assertSame('value', $message->nested->value);
        } finally {
            $observer->close();
        }
    }

    #[DataProvider('failures')]
    public function testFailureRollsBackSnapshotAndBusinessWrite(string $failure): void
    {
        try {
            $this->db->transactional(function (Connection $db) use ($failure): void {
                $db->insert('public.outbox_test_business', ['operation_id' => 'rollback-1']);
                $message = 'serialize' === $failure ? new UnserializableMessage() : new \stdClass();
                if ('insert' === $failure) {
                    $db->executeStatement('ALTER TABLE shared.outbox_messages ADD CONSTRAINT reject_test_insert CHECK (false)');
                }
                new DoctrineOutboxWriter($db, new MockClock())->append($message);
                throw new \RuntimeException('Failure after append');
            });
        } catch (\Exception $e) {
            if ('insert' === $failure) {
                self::assertInstanceOf(\Doctrine\DBAL\Exception\DriverException::class, $e);
                self::assertSame('23514', $e->getSQLState());
            } else {
                self::assertInstanceOf(\RuntimeException::class, $e);
                self::assertSame('serialize' === $failure ? 'Cannot serialize' : 'Failure after append', $e->getMessage());
            }
            self::assertSame(0, $this->db->fetchOne('SELECT COUNT(*) FROM public.outbox_test_business'));
            self::assertSame(0, $this->db->fetchOne('SELECT COUNT(*) FROM shared.outbox_messages'));
        }
    }

    /** @return iterable<string, array{string}> */
    public static function failures(): iterable
    {
        yield 'later failure' => ['later'];
        yield 'serialization failure' => ['serialize'];
        yield 'insert failure' => ['insert'];
    }

    public function testAnotherConnectionCannotReadUncommittedSnapshot(): void
    {
        $observer = $this->observer();
        try {
            $this->db->beginTransaction();
            new DoctrineOutboxWriter($this->db, new MockClock())->append(new \stdClass());
            self::assertSame(0, $observer->fetchOne('SELECT COUNT(*) FROM shared.outbox_messages'));
            $this->db->commit();
            self::assertSame(1, $observer->fetchOne('SELECT COUNT(*) FROM shared.outbox_messages'));
        } finally {
            $observer->close();
        }
    }
}
