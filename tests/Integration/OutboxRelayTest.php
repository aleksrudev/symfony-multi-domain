<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Shared\Infrastructure\Messaging\Outbox\Publisher\OutboxPublisherInterface;
use App\Shared\Infrastructure\Messaging\Outbox\Relay\OutboxRelay;
use App\Shared\Infrastructure\Messaging\Outbox\Writer\DoctrineOutboxWriter;
use Doctrine\DBAL\Exception\ConnectionException;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Log\AbstractLogger;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Process\Process;
use Tests\Fixtures\Messaging\ExplodingRestoreMessage;
use Tests\Fixtures\Messaging\NestedMessageValue;
use Tests\Fixtures\Messaging\OutboxStorageTestCase;
use Tests\Fixtures\Messaging\PlainMessage;
use Tests\Fixtures\Messaging\RecordingOutboxPublisher;

final class OutboxRelayTest extends OutboxStorageTestCase
{
    public function testRelayRestoresDifferentPlainObjects(): void
    {
        $clock = new MockClock('2026-10-26T09:00:00Z');
        $this->append(new PlainMessage('relay-1', new NestedMessageValue('nested'), "a\0b"), $clock);
        $this->append((object) ['another' => new \ArrayObject([42])], $clock);
        $relay = new OutboxRelay($this->db, $clock, new RecordingOutboxPublisher($this->db));
        self::assertTrue($relay->relayOne());
        self::assertTrue($relay->relayOne());
        self::assertFalse($relay->relayOne());
        self::assertFalse($this->db->isTransactionActive());
        $bodies = $this->db->fetchFirstColumn('SELECT body FROM public.outbox_test_deliveries ORDER BY id');
        $first = unserialize(base64_decode($bodies[0], true));
        self::assertInstanceOf(PlainMessage::class, $first);
        self::assertSame('relay-1', $first->operationId);
        self::assertSame('nested', $first->nested->value);
        self::assertSame("a\0b", $first->binary);
        self::assertEquals((object) ['another' => new \ArrayObject([42])], unserialize(base64_decode($bodies[1], true)));
        self::assertSame(0, $this->db->fetchOne('SELECT COUNT(*) FROM shared.outbox_messages'));
    }

    public function testSendFailuresBackOffAndRetainSnapshotForRecovery(): void
    {
        $clock = new MockClock('2026-10-26T09:00:00.123456Z');
        $this->append((object) ['secret' => 'sensitive-body'], $clock);
        $body = $this->db->fetchOne('SELECT body FROM shared.outbox_messages');
        $logger = new class extends AbstractLogger {
            /** @var list<array{mixed, string, array<string, mixed>}> */
            public array $records = [];

            public function log($level, string|\Stringable $message, array $context = []): void
            {
                $this->records[] = [$level, (string) $message, $context];
            }
        };
        $publisher = new class implements OutboxPublisherInterface {
            public function publish(object $message): void
            {
                throw new \RuntimeException('sensitive-body');
            }
        };
        $relay = new OutboxRelay($this->db, $clock, $publisher, $logger);
        self::assertTrue($relay->relayOne());
        self::assertSame('2026-10-26 09:00:01.123456', $this->db->fetchOne('SELECT available_at FROM shared.outbox_messages'));
        self::assertFalse($relay->relayOne());
        $clock->sleep(1);
        self::assertTrue($relay->relayOne());
        self::assertSame('2026-10-26 09:00:04.123456', $this->db->fetchOne('SELECT available_at FROM shared.outbox_messages'));
        $clock->sleep(3);
        self::assertTrue($relay->relayOne());
        self::assertSame('2026-10-26 09:00:13.123456', $this->db->fetchOne('SELECT available_at FROM shared.outbox_messages'));
        $clock->sleep(9);
        self::assertTrue($relay->relayOne());
        $row = $this->db->fetchAssociative('SELECT * FROM shared.outbox_messages');
        self::assertSame(4, $row['attempts']);
        self::assertSame('2026-10-26 09:00:13.123456', $row['failed_at']);
        self::assertSame($body, $row['body']);
        self::assertSame(\RuntimeException::class, $row['last_error']);
        self::assertFalse($relay->relayOne());
        self::assertCount(4, $logger->records);
        self::assertSame(4, $logger->records[3][2]['attempt']);
        self::assertSame((string) $row['id'], (string) $logger->records[3][2]['outbox_id']);
        self::assertStringNotContainsString('sensitive-body', serialize($logger->records));
        self::assertStringNotContainsString($body, serialize($logger->records));
    }

    #[DataProvider('invalidBodies')]
    public function testDecodeFailureRetainsRowAndContinuesWithNextMessage(string $body): void
    {
        $clock = new MockClock('2026-10-26T09:00:00Z');
        $this->db->insert('shared.outbox_messages', ['body' => $body, 'created_at' => '2026-10-26 08:00:00', 'available_at' => '2026-10-26 08:00:00']);
        $this->append((object) ['valid' => true], $clock);
        $relay = new OutboxRelay($this->db, $clock, new RecordingOutboxPublisher($this->db));
        self::assertTrue($relay->relayOne());
        self::assertTrue($relay->relayOne());
        self::assertSame(1, $this->db->fetchOne('SELECT COUNT(*) FROM public.outbox_test_deliveries'));
        self::assertSame($body, $this->db->fetchOne('SELECT body FROM shared.outbox_messages'));
        self::assertSame(1, $this->db->fetchOne('SELECT attempts FROM shared.outbox_messages'));
    }

    /** @return iterable<string, array{string}> */
    public static function invalidBodies(): iterable
    {
        yield 'invalid base64' => ['@bad'];
        yield 'invalid serialization' => [base64_encode('garbage')];
        yield 'scalar' => [base64_encode(serialize('scalar'))];
        yield 'unknown class' => [base64_encode('O:7:"Missing":0:{}')];
        yield 'nested incomplete class' => [base64_encode('O:8:"stdClass":1:{s:5:"child";O:7:"Missing":0:{}}')];
        yield 'restore callback throws' => [base64_encode(serialize(new ExplodingRestoreMessage()))];
        $missing = unserialize('O:7:"Missing":0:{}');
        $storage = new \SplObjectStorage();
        $storage[$missing] = 'value';
        $list = new \SplDoublyLinkedList();
        $list->push($missing);
        foreach ([
            'ArrayObject' => new \ArrayObject([$missing]),
            'ArrayIterator' => new \ArrayIterator([$missing]),
            'SplObjectStorage' => $storage,
            'SplFixedArray' => \SplFixedArray::fromArray([$missing]),
            'SplDoublyLinkedList' => $list,
            'explicit incomplete class' => new \ArrayObject([new \__PHP_Incomplete_Class()]),
        ] as $name => $container) {
            yield $name => [base64_encode(serialize((object) ['items' => $container]))];
        }
        yield 'trailing bytes' => [base64_encode(serialize(new \stdClass()).'garbage')];
    }

    public function testDeleteFailureRollsBackDatabasePublisherAndAllowsExternalDuplicate(): void
    {
        $clock = new MockClock('2026-10-26T09:00:00Z');
        $this->append((object) ['operation' => 'duplicate-1'], $clock);
        $this->db->executeStatement("CREATE FUNCTION public.outbox_test_reject_delete() RETURNS trigger LANGUAGE plpgsql AS 'BEGIN RAISE EXCEPTION ''reject deletion''; END'");
        $this->db->executeStatement('CREATE TRIGGER outbox_test_reject_delete BEFORE DELETE ON shared.outbox_messages FOR EACH ROW EXECUTE FUNCTION public.outbox_test_reject_delete()');
        $file = tempnam(sys_get_temp_dir(), 'outbox-effect-');
        $publisher = new class($this->db, $file) implements OutboxPublisherInterface {
            public function __construct(private \Doctrine\DBAL\Connection $db, private string $file)
            {
            }

            public function publish(object $message): void
            {
                new RecordingOutboxPublisher($this->db)->publish($message);
                file_put_contents($this->file, $message->operation."\n", FILE_APPEND);
            }
        };
        $relay = new OutboxRelay($this->db, $clock, $publisher);
        try {
            self::assertTrue($relay->relayOne());
            self::assertSame(0, $this->db->fetchOne('SELECT COUNT(*) FROM public.outbox_test_deliveries'));
            self::assertSame(1, $this->db->fetchOne('SELECT attempts FROM shared.outbox_messages'));
            self::assertSame("duplicate-1\n", file_get_contents($file));
        } finally {
            $this->db->executeStatement('DROP TRIGGER outbox_test_reject_delete ON shared.outbox_messages');
            $this->db->executeStatement('DROP FUNCTION public.outbox_test_reject_delete()');
        }
        try {
            $clock->sleep(1);
            self::assertTrue($relay->relayOne());
            self::assertSame("duplicate-1\nduplicate-1\n", file_get_contents($file));
            self::assertSame(1, $this->db->fetchOne('SELECT COUNT(*) FROM public.outbox_test_deliveries'));
        } finally {
            unlink($file);
        }
    }

    public function testRelayRejectsAnExistingTransaction(): void
    {
        $this->db->beginTransaction();
        $this->expectException(\LogicException::class);
        new OutboxRelay($this->db, new MockClock(), new RecordingOutboxPublisher($this->db))->relayOne();
    }

    public function testConnectionLossIsPropagatedWithoutRecordingAnAttempt(): void
    {
        $clock = new MockClock();
        $this->append(new \stdClass(), $clock);
        $relayDb = $this->observer();
        $pid = $relayDb->fetchOne('SELECT pg_backend_pid()');
        $this->db->executeStatement('SELECT pg_terminate_backend(?)', [$pid]);
        try {
            new OutboxRelay($relayDb, $clock, new RecordingOutboxPublisher($relayDb))->relayOne();
            self::fail('Connection loss must escape the relay.');
        } catch (ConnectionException) {
            self::assertSame(0, $this->db->fetchOne('SELECT attempts FROM shared.outbox_messages'));
        } finally {
            $relayDb->close();
        }
    }

    public function testConcurrentRelaySkipsLockedRow(): void
    {
        $clock = new MockClock();
        $this->append((object) ['operation' => 'locked'], $clock);
        $this->append((object) ['operation' => 'other'], $clock);
        $barrier = tempnam(sys_get_temp_dir(), 'outbox-barrier-');
        unlink($barrier);
        $process = new Process([PHP_BINARY, dirname(__DIR__).'/Fixtures/Messaging/relay-process.php', $barrier]);
        $process->start();
        try {
            $deadline = microtime(true) + 5;
            while (!is_file($barrier) && $process->isRunning() && microtime(true) < $deadline) {
                usleep(10000);
            }
            self::assertFileExists($barrier, $process->getErrorOutput());
            $relay = new OutboxRelay($this->db, $clock, new RecordingOutboxPublisher($this->db));
            self::assertTrue($relay->relayOne());
            self::assertFalse($relay->relayOne());
            $body = $this->db->fetchOne('SELECT body FROM public.outbox_test_deliveries');
            self::assertSame('other', unserialize(base64_decode($body, true))->operation);
            touch($barrier.'.release');
            self::assertSame(0, $process->wait(), $process->getErrorOutput());
            self::assertSame(2, $this->db->fetchOne('SELECT COUNT(*) FROM public.outbox_test_deliveries'));
            self::assertSame(0, $this->db->fetchOne('SELECT COUNT(*) FROM shared.outbox_messages'));
        } finally {
            $process->stop();
            @unlink($barrier);
            @unlink($barrier.'.release');
        }
    }

    public function testOuterCommitFailureDoesNotAcknowledgeSnapshot(): void
    {
        $clock = new MockClock();
        $this->append(new \stdClass(), $clock);
        $this->db->executeStatement("CREATE FUNCTION public.outbox_test_reject_commit() RETURNS trigger LANGUAGE plpgsql AS 'BEGIN RAISE EXCEPTION USING ERRCODE = ''23503'', MESSAGE = ''commit rejected''; END'");
        $this->db->executeStatement('CREATE CONSTRAINT TRIGGER outbox_test_reject_commit AFTER INSERT ON public.outbox_test_deliveries DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION public.outbox_test_reject_commit()');
        try {
            try {
                new OutboxRelay($this->db, $clock, new RecordingOutboxPublisher($this->db))->relayOne();
                self::fail('Commit failure must escape the relay.');
            } catch (\Doctrine\DBAL\Exception\ForeignKeyConstraintViolationException) {
                self::assertSame(0, $this->db->fetchOne('SELECT COUNT(*) FROM public.outbox_test_deliveries'));
                self::assertSame(0, $this->db->fetchOne('SELECT attempts FROM shared.outbox_messages'));
                self::assertFalse($this->db->isTransactionActive());
            }
        } finally {
            $this->db->executeStatement('DROP TRIGGER outbox_test_reject_commit ON public.outbox_test_deliveries');
            $this->db->executeStatement('DROP FUNCTION public.outbox_test_reject_commit()');
        }
    }

    public function testReferenceCyclesSurviveSnapshotRestoration(): void
    {
        $clock = new MockClock();
        $array = ['value' => 'cyclic'];
        $array['self'] = &$array;
        $message = (object) ['references' => $array];
        $message->self = $message;
        $this->append($message, $clock);
        self::assertTrue(new OutboxRelay($this->db, $clock, new RecordingOutboxPublisher($this->db))->relayOne());
        $restored = unserialize(base64_decode($this->db->fetchOne('SELECT body FROM public.outbox_test_deliveries'), true));
        self::assertSame($restored, $restored->self);
        self::assertSame('cyclic', $restored->references['self']['self']['value']);
    }

    private function append(object $message, MockClock $clock): void
    {
        $this->db->transactional(fn () => new DoctrineOutboxWriter($this->db, $clock)->append($message));
    }
}
