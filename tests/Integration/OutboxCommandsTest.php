<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Shared\Infrastructure\Messaging\Outbox\Console\RelayOutboxCommand;
use App\Shared\Infrastructure\Messaging\Outbox\Console\RetryOutboxCommand;
use App\Shared\Infrastructure\Messaging\Outbox\Relay\OutboxRelay;
use App\Shared\Infrastructure\Messaging\Outbox\Writer\DoctrineOutboxWriter;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Process\Process;
use Tests\Fixtures\Messaging\OutboxStorageTestCase;
use Tests\Fixtures\Messaging\RecordingOutboxPublisher;

final class OutboxCommandsTest extends OutboxStorageTestCase
{
    public function testRelayStopsAtLimitAndIsDiscoverableInTestKernel(): void
    {
        $clock = new MockClock('2026-10-26T09:00:00Z');
        $this->append($clock);
        $this->append($clock);
        $this->append($clock);
        $command = new Application($this->kernel)->find('app:outbox:relay');
        self::assertSame(0, new CommandTester($command)->execute(['--limit' => '2', '--time-limit' => '1']));
        self::assertSame(2, $this->db->fetchOne('SELECT COUNT(*) FROM public.outbox_test_deliveries'));
        self::assertSame(1, $this->db->fetchOne('SELECT COUNT(*) FROM shared.outbox_messages'));
    }

    public function testEmptyQueueSleepsUntilTimeLimit(): void
    {
        $clock = new MockClock('2026-10-26T09:00:00Z');
        $command = new RelayOutboxCommand(new OutboxRelay($this->db, $clock, new RecordingOutboxPublisher($this->db)), $clock);
        self::assertSame(0, new CommandTester($command)->execute(['--time-limit' => '1', '--sleep' => '250']));
        self::assertSame('09:00:01.000000', $clock->now()->format('H:i:s.u'));
        self::assertFalse($this->db->isTransactionActive());
    }

    #[DataProvider('invalidOptions')]
    public function testRelayRejectsInvalidOptions(string $option, string $value): void
    {
        $clock = new MockClock();
        $command = new RelayOutboxCommand(new OutboxRelay($this->db, $clock, new RecordingOutboxPublisher($this->db)), $clock);
        self::assertNotSame(0, new CommandTester($command)->execute([$option => $value]));
        self::assertFalse($this->db->isTransactionActive());
    }

    /** @return iterable<array{string, string}> */
    public static function invalidOptions(): iterable
    {
        yield 'zero limit' => ['--limit', '0'];
        yield 'negative limit' => ['--limit', '-1'];
        yield 'fractional time' => ['--time-limit', '0.5'];
        yield 'invalid time' => ['--time-limit', 'invalid'];
        yield 'zero sleep' => ['--sleep', '0'];
        yield 'negative sleep' => ['--sleep', '-1'];
        yield 'overflow limit' => ['--limit', '999999999999999999999'];
    }

    public function testRetryRestoresOneFailedSnapshot(): void
    {
        $clock = new MockClock('2026-10-26T09:00:00.123456Z');
        $this->append($clock);
        $row = $this->db->fetchAssociative('SELECT * FROM shared.outbox_messages');
        $this->db->executeStatement("UPDATE shared.outbox_messages SET attempts = 4, failed_at = '2026-10-26 09:01:00', last_error = 'error', available_at = '2026-10-26 09:01:00'");
        $command = new RetryOutboxCommand($this->db, $clock);
        self::assertSame(0, new CommandTester($command)->execute(['id' => (string) $row['id']]));
        $restored = $this->db->fetchAssociative('SELECT * FROM shared.outbox_messages');
        self::assertSame($row['body'], $restored['body']);
        self::assertSame($row['created_at'], $restored['created_at']);
        self::assertSame(0, $restored['attempts']);
        self::assertNull($restored['failed_at']);
        self::assertNull($restored['last_error']);
        self::assertSame('2026-10-26 09:00:00.123456', $restored['available_at']);
        self::assertTrue(new OutboxRelay($this->db, $clock, new RecordingOutboxPublisher($this->db))->relayOne());
        $message = unserialize(base64_decode($this->db->fetchOne('SELECT body FROM public.outbox_test_deliveries'), true));
        self::assertSame('command-probe', $message->operation);
    }

    #[DataProvider('invalidIds')]
    public function testRetryRejectsInvalidOrMissingIds(string $id): void
    {
        $clock = new MockClock();
        $this->append($clock);
        $before = $this->db->fetchAllAssociative('SELECT * FROM shared.outbox_messages');
        self::assertNotSame(0, new CommandTester(new RetryOutboxCommand($this->db, $clock))->execute(['id' => $id]));
        self::assertSame($before, $this->db->fetchAllAssociative('SELECT * FROM shared.outbox_messages'));
    }

    /** @return iterable<array{string}> */
    public static function invalidIds(): iterable
    {
        yield ['0'];
        yield ['-1'];
        yield ['bad'];
        yield ['999999999999999999999'];
        yield ['9223372036854775807'];
    }

    public function testRetryDoesNotResetPendingRecord(): void
    {
        $clock = new MockClock();
        $this->append($clock);
        $this->db->executeStatement('UPDATE shared.outbox_messages SET attempts = 1');
        $row = $this->db->fetchAssociative('SELECT * FROM shared.outbox_messages');
        self::assertNotSame(0, new CommandTester(new RetryOutboxCommand($this->db, $clock))->execute(['id' => (string) $row['id']]));
        self::assertSame($row, $this->db->fetchAssociative('SELECT * FROM shared.outbox_messages'));
    }

    public function testSigtermFinishesCurrentTransactionBeforeStopping(): void
    {
        $clock = new MockClock();
        $this->append($clock);
        $this->append($clock);
        $barrier = tempnam(sys_get_temp_dir(), 'outbox-signal-');
        unlink($barrier);
        $process = new Process([PHP_BINARY, dirname(__DIR__).'/Fixtures/Messaging/relay-process.php', $barrier, 'command']);
        $process->start();
        try {
            $deadline = microtime(true) + 5;
            while (!is_file($barrier) && $process->isRunning() && microtime(true) < $deadline) {
                usleep(10000);
            }
            self::assertFileExists($barrier, $process->getErrorOutput());
            $process->signal(SIGTERM);
            touch($barrier.'.release');
            self::assertSame(0, $process->wait(), $process->getErrorOutput());
            self::assertSame(1, $this->db->fetchOne('SELECT COUNT(*) FROM public.outbox_test_deliveries'));
            self::assertSame(1, $this->db->fetchOne('SELECT COUNT(*) FROM shared.outbox_messages'));
        } finally {
            $process->stop();
            @unlink($barrier);
            @unlink($barrier.'.release');
        }
    }

    private function append(MockClock $clock): void
    {
        $this->db->transactional(fn () => new DoctrineOutboxWriter($this->db, $clock)->append((object) ['operation' => 'command-probe']));
    }
}
