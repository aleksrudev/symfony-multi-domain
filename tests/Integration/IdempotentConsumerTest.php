<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Shared\Infrastructure\Messaging\IdempotentConsumer\Identity\MessageIdentity;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Process\Process;
use Tests\Fixtures\Messaging\Consumer\ConsumerStorageTestCase;

final class IdempotentConsumerTest extends ConsumerStorageTestCase
{
    public function testRepeatedIdentityDoesNotRepeatEffect(): void
    {
        $consumer = $this->consumer();
        $identity = new MessageIdentity('provider.webhook', 'delivery-42');
        $consumer->consume('record-delivery', $identity, $this->increment(...));
        $consumer->consume('record-delivery', $identity, $this->increment(...));
        self::assertSame(1, $this->effect());
        self::assertSame(1, $this->db->fetchOne('SELECT COUNT(*) FROM shared.processed_messages'));
        self::assertSame('2026-10-26 09:00:00.123456', $this->db->fetchOne('SELECT processed_at FROM shared.processed_messages'));
        self::assertFalse($this->db->isTransactionActive());
    }

    public function testDifferentConsumersProcessSameIdentity(): void
    {
        $consumer = $this->consumer();
        $identity = new MessageIdentity('provider.webhook', 'delivery-42');
        $consumer->consume('record-delivery', $identity, $this->increment(...));
        $consumer->consume('count-delivery', $identity, $this->increment(...));
        self::assertSame(2, $this->effect());
        self::assertSame(2, $this->db->fetchOne('SELECT COUNT(*) FROM shared.processed_messages'));
    }

    public function testSameIdFromDifferentNamespacesDoesNotCollide(): void
    {
        $consumer = $this->consumer();
        $consumer->consume('record-delivery', new MessageIdentity('provider.webhook', 'delivery-42'), $this->increment(...));
        $consumer->consume('record-delivery', new MessageIdentity('batch.import', 'delivery-42'), $this->increment(...));
        self::assertSame(2, $this->effect());
        self::assertSame(2, $this->db->fetchOne('SELECT COUNT(*) FROM shared.processed_messages'));
    }

    public function testDifferentIdsInOneNamespaceRemainIndependent(): void
    {
        $consumer = $this->consumer();
        $consumer->consume('record-delivery', new MessageIdentity('provider.webhook', 'delivery-42'), $this->increment(...));
        $consumer->consume('record-delivery', new MessageIdentity('provider.webhook', 'delivery-43'), $this->increment(...));
        self::assertSame(2, $this->effect());
    }

    public function testFailureRollsBackReceiptAndEffect(): void
    {
        $consumer = $this->consumer();
        $identity = new MessageIdentity('provider.webhook', 'delivery-42');
        $failure = new \RuntimeException('Callback failed');
        try {
            $consumer->consume('record-delivery', $identity, function () use ($failure): void {
                $this->increment();
                throw $failure;
            });
            self::fail('Expected callback failure.');
        } catch (\RuntimeException $caught) {
            self::assertSame($failure, $caught);
        }
        $observer = $this->observer();
        try {
            self::assertSame(0, $observer->fetchOne('SELECT COUNT(*) FROM shared.processed_messages'));
            self::assertSame(0, $observer->fetchOne("SELECT value FROM public.consumer_test_probe WHERE id = 'effect'"));
        } finally {
            $observer->close();
        }
        $consumer->consume('record-delivery', $identity, $this->increment(...));
        self::assertSame(1, $this->effect());
    }

    public function testOuterTransactionIsRejected(): void
    {
        $consumer = $this->consumer();
        $this->db->beginTransaction();
        $this->db->insert('public.consumer_test_probe', ['id' => 'caller', 'value' => 1]);
        try {
            $consumer->consume('record-delivery', new MessageIdentity('provider.webhook', 'delivery-42'), static function (): void {
                self::fail('Callback must not run within an existing transaction.');
            });
            self::fail('Expected transaction boundary rejection.');
        } catch (\LogicException) {
            self::assertTrue($this->db->isTransactionActive());
            self::assertSame(1, $this->db->fetchOne("SELECT value FROM public.consumer_test_probe WHERE id = 'caller'"));
            self::assertSame(0, $this->db->fetchOne('SELECT COUNT(*) FROM shared.processed_messages'));
        }
    }

    #[DataProvider('invalidConsumerIds')]
    public function testInvalidConsumerIdDoesNotRunCallbackOrWriteReceipt(string $consumerId): void
    {
        $consumer = $this->consumer();
        try {
            $consumer->consume($consumerId, new MessageIdentity('provider.webhook', 'delivery-42'), static function (): void {
                self::fail('Invalid consumer ID must not run a callback.');
            });
            self::fail('Expected invalid consumer ID.');
        } catch (\InvalidArgumentException) {
            self::assertSame(0, $this->db->fetchOne('SELECT COUNT(*) FROM shared.processed_messages'));
            self::assertFalse($this->db->isTransactionActive());
        }
    }

    /** @return iterable<array{string}> */
    public static function invalidConsumerIds(): iterable
    {
        yield [''];
        yield [str_repeat('x', 191)];
        yield [str_repeat('🙂', 191)];
        yield ["handler\0name"];
        yield ["\xFF"];
    }

    public function testOldReceiptsDoNotExpire(): void
    {
        $consumer = $this->consumer();
        $identity = new MessageIdentity('provider.webhook', 'delivery-42');
        $consumer->consume(str_repeat('🙂', 190), $identity, $this->increment(...));
        $this->db->executeStatement("UPDATE shared.processed_messages SET processed_at = '2000-01-01'");
        $consumer->consume(str_repeat('🙂', 190), $identity, $this->increment(...));
        self::assertSame(1, $this->effect());
        self::assertSame('2000-01-01 00:00:00', $this->db->fetchOne('SELECT processed_at FROM shared.processed_messages'));
    }

    #[DataProvider('winningTransactions')]
    public function testTwoProcessesApplyOneEffect(bool $firstCommits): void
    {
        $this->consumer();
        $barrier = tempnam(sys_get_temp_dir(), 'consumer-barrier-');
        unlink($barrier);
        $script = dirname(__DIR__).'/Fixtures/Messaging/Consumer/consumer-process.php';
        $first = new Process([PHP_BINARY, $script, $barrier, $firstCommits ? 'commit' : 'rollback']);
        $second = new Process([PHP_BINARY, $script, $barrier, 'second']);
        $first->start();
        try {
            $this->waitForFile($barrier.'.locked', $first);
            $second->start();
            $this->waitForFile($barrier.'.waiting', $second);
            $pid = (int) file_get_contents($barrier.'.waiting');
            $deadline = microtime(true) + 5;
            do {
                $wait = $this->db->fetchOne('SELECT wait_event_type FROM pg_stat_activity WHERE pid = ?', [$pid]);
                if ('Lock' === $wait || !$second->isRunning()) {
                    break;
                }
                usleep(10000);
            } while (microtime(true) < $deadline);
            self::assertSame('Lock', $wait, 'The second transaction must wait for the conflicting receipt.');
            touch($barrier.'.release');
            self::assertSame(0, $first->wait(), $first->getErrorOutput());
            self::assertSame(0, $second->wait(), $second->getErrorOutput());
            self::assertSame(1, $this->effect());
            self::assertSame(1, $this->db->fetchOne('SELECT COUNT(*) FROM shared.processed_messages'));
            self::assertSame($firstCommits ? ['effect', 'first'] : ['effect', 'second'], $this->db->fetchFirstColumn('SELECT id FROM public.consumer_test_probe ORDER BY id'));
        } finally {
            $first->stop();
            if ($second->isStarted()) {
                $second->stop();
            }
            foreach (['.locked', '.waiting', '.release'] as $suffix) {
                @unlink($barrier.$suffix);
            }
        }
    }

    /** @return iterable<array{bool}> */
    public static function winningTransactions(): iterable
    {
        yield 'first commits' => [true];
        yield 'first rolls back' => [false];
    }

    private function waitForFile(string $file, Process $process): void
    {
        $deadline = microtime(true) + 5;
        while (!is_file($file) && $process->isRunning() && microtime(true) < $deadline) {
            usleep(10000);
        }
        self::assertFileExists($file, $process->getErrorOutput());
    }

    private function increment(): void
    {
        $this->db->executeStatement("UPDATE public.consumer_test_probe SET value = value + 1 WHERE id = 'effect'");
    }

    private function effect(): int
    {
        return $this->db->fetchOne("SELECT value FROM public.consumer_test_probe WHERE id = 'effect'");
    }
}
