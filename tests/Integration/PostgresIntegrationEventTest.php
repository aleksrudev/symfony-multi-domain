<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Shared\Application\Bus\Command\CommandBusInterface;
use App\Shared\Infrastructure\Messaging\IntegrationEvent\IntegrationEventDispatcherInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;
use Symfony\Component\Messenger\Stamp\HandledStamp;
use Symfony\Component\Process\Process;
use Tests\Fixtures\FailFirstMessagingKernel;
use Tests\Fixtures\Messaging\Integration\MessagingTestCase;
use Tests\Fixtures\Messaging\Integration\ProcessBarrier;
use Tests\Fixtures\Messaging\Integration\UnserializableEvent;
use Tests\Fixtures\Modules\Alpha\Application\PublishProbeEventCommand;
use Tests\Fixtures\Modules\Alpha\Domain\ProbePublishedDomainEvent;
use Tests\Fixtures\Modules\Alpha\Presentation\Contracts\Event\ProbePublishedEvent;
use Tests\Fixtures\Modules\Beta\Presentation\Contracts\Event\ProbeRecordedEvent;

final class PostgresIntegrationEventTest extends MessagingTestCase
{
    public function testSourceAndRelayChangesBecomeVisibleOnlyAfterTheirCommits(): void
    {
        $observer = $this->observer();
        try {
            $this->db->beginTransaction();
            $this->container->get(CommandBusInterface::class)->dispatch(new PublishProbeEventCommand(7));
            self::assertSame(0, $observer->fetchOne('SELECT COUNT(*) FROM alpha.txn_probe'));
            self::assertSame(0, $observer->fetchOne('SELECT COUNT(*) FROM shared.outbox_messages'));
            $this->db->commit();
            self::assertSame(1, $observer->fetchOne('SELECT COUNT(*) FROM alpha.txn_probe'));
            self::assertSame(1, $observer->fetchOne('SELECT COUNT(*) FROM shared.outbox_messages'));
            $this->relay();
            self::assertSame(0, $observer->fetchOne('SELECT COUNT(*) FROM shared.outbox_messages'));
            self::assertSame(1, $observer->fetchOne("SELECT COUNT(*) FROM public.messenger_messages WHERE queue_name = 'async'"));
        } finally {
            $observer->close();
        }
    }

    public function testFailureAfterAsyncInsertBeforeDeleteRollsBackBoth(): void
    {
        $event = $this->source();
        $this->db->executeStatement("CREATE OR REPLACE FUNCTION shared.reject_outbox_delete() RETURNS trigger LANGUAGE plpgsql AS 'BEGIN RAISE EXCEPTION ''fixture delete failure''; END'");
        $this->db->executeStatement('CREATE TRIGGER reject_outbox_delete BEFORE DELETE ON shared.outbox_messages FOR EACH ROW EXECUTE FUNCTION shared.reject_outbox_delete()');
        $this->relay();
        self::assertSame(0, $this->countQueue('async'));
        $row = $this->db->fetchAssociative('SELECT * FROM shared.outbox_messages');
        self::assertSame(1, $row['attempts']);
        self::assertEquals($event, unserialize(base64_decode($row['body'], true)));
        self::assertNull($row['failed_at']);
        $this->db->executeStatement('DROP TRIGGER reject_outbox_delete ON shared.outbox_messages');
        $this->db->executeStatement('UPDATE shared.outbox_messages SET available_at = CURRENT_TIMESTAMP');
        $this->relay();
        self::assertSame(1, $this->countQueue('async'));
        self::assertSame(0, $this->db->fetchOne('SELECT COUNT(*) FROM shared.outbox_messages'));
    }

    public function testUncommittedSourceIsInvisibleToRelay(): void
    {
        $this->db->beginTransaction();
        $this->container->get(CommandBusInterface::class)->dispatch(new PublishProbeEventCommand(17));
        $event = unserialize(base64_decode($this->db->fetchOne('SELECT body FROM shared.outbox_messages'), true));
        self::assertInstanceOf(ProbePublishedEvent::class, $event);
        $observer = $this->observer();
        try {
            self::assertSame(0, $observer->fetchOne('SELECT COUNT(*) FROM alpha.txn_probe WHERE value = 17'));
            self::assertSame(0, $observer->fetchOne('SELECT COUNT(*) FROM shared.outbox_messages'));
            $relay = $this->relayProcess();
            self::assertSame(0, $relay->run(), $relay->getErrorOutput());
            self::assertStringContainsString('relayed=0', $relay->getOutput());
            self::assertSame(0, $this->countQueue('async'));
            $this->db->commit();
            self::assertSame(1, $observer->fetchOne('SELECT COUNT(*) FROM alpha.txn_probe WHERE value = 17'));
            self::assertSame(0, $relay->run(), $relay->getErrorOutput());
            self::assertStringContainsString('relayed=1', $relay->getOutput());
            self::assertEquals($event, $this->envelopes('async')[0]->getMessage());
        } finally {
            $observer->close();
        }
    }

    public function testSnapshotFailureRollsBackSource(): void
    {
        $dispatcher = $this->container->get(IntegrationEventDispatcherInterface::class);
        $listener = static function () use ($dispatcher): void {
            $dispatcher->dispatch(new UnserializableEvent('snapshot-failure', static fn (): string => 'not serializable'));
        };
        $events = $this->container->get('event_dispatcher');
        $events->addListener(ProbePublishedDomainEvent::class, $listener, 100);
        try {
            $this->container->get(CommandBusInterface::class)->dispatch(new PublishProbeEventCommand(27));
            self::fail('Expected native snapshot serialization failure.');
        } catch (\Exception $error) {
            self::assertStringContainsString('Closure', $error->getMessage());
            $observer = $this->observer();
            try {
                self::assertSame(0, $observer->fetchOne('SELECT COUNT(*) FROM alpha.txn_probe WHERE value = 27'));
                self::assertSame(0, $observer->fetchOne('SELECT COUNT(*) FROM shared.outbox_messages'));
                self::assertSame(0, $this->countQueue('async'));
            } finally {
                $observer->close();
            }
        } finally {
            $events->removeListener(ProbePublishedDomainEvent::class, $listener);
        }
    }

    public function testCrashAfterConsumerCommitBeforeAckDoesNotRepeatEffect(): void
    {
        $event = $this->source();
        self::assertInstanceOf(ProbePublishedEvent::class, $event);
        $this->relay();
        $queueId = $this->db->fetchOne("SELECT id FROM public.messenger_messages WHERE queue_name = 'async'");
        $barrier = $this->barrier();
        $worker = $this->consumerProcess('before-ack', $barrier);
        $worker->start();
        try {
            self::assertSame($event->eventId, $barrier->wait('committed'));
            $this->assertOneReactionEach($event);
            $nextFacts = $this->nextFacts($event);
            self::assertCount(1, $nextFacts);
            self::assertIsString($this->db->fetchOne('SELECT delivered_at FROM public.messenger_messages WHERE id = ?', [$queueId]));
            self::assertNull($this->envelopes('async')[0]->last(HandledStamp::class));
            $backend = (int) $barrier->wait('received');
            $worker->signal(SIGKILL);
            self::assertNotSame(0, $worker->wait());
            $this->waitForBackendExit($backend);
            self::assertSame(1, $this->countQueue('async'), 'Commit must precede acknowledgement.');
            // Expire only the dead worker's lease instead of waiting an hour.
            $this->db->executeStatement("UPDATE public.messenger_messages SET delivered_at = CURRENT_TIMESTAMP - INTERVAL '2 hours' WHERE id = ?", [$queueId]);
            $redelivery = $this->consumerProcess('normal', $barrier);
            try {
                self::assertSame(0, $redelivery->run(), $redelivery->getErrorOutput());
            } finally {
                $redelivery->stop(0, SIGKILL);
            }
            self::assertSame(0, $this->countQueue('async'));
            $this->assertOneReactionEach($event);
            self::assertEquals($nextFacts, $this->nextFacts($event));
        } finally {
            $worker->stop(0, SIGKILL);
            $barrier->clear();
        }
    }

    public function testConcurrentWorkersCommitOneReaction(): void
    {
        $event = $this->source();
        self::assertInstanceOf(ProbePublishedEvent::class, $event);
        $this->relay();
        $this->container->get('event.bus')->dispatch(new Envelope($event));
        $lock = random_int(1000000, 2000000);
        $this->db->executeStatement('SELECT pg_advisory_lock(?)', [$lock]);
        $this->db->executeStatement("CREATE OR REPLACE FUNCTION beta.hold_recording() RETURNS trigger LANGUAGE plpgsql AS 'BEGIN PERFORM pg_advisory_xact_lock(".$lock."); RETURN NEW; END'");
        $this->db->executeStatement('CREATE TRIGGER hold_recording BEFORE INSERT ON beta.probe_recorded FOR EACH ROW EXECUTE FUNCTION beta.hold_recording()');
        $one = $this->barrier();
        $two = $this->barrier();
        $first = $this->consumerProcess('normal', $one);
        $second = $this->consumerProcess('normal', $two);
        $locked = true;
        try {
            $first->start();
            $this->waitForDatabaseLock((int) $one->wait('received'), 'advisory');
            $second->start();
            $this->waitForDatabaseLock((int) $two->wait('received'), 'transactionid');
            self::assertSame([], $this->receipts($event));
            self::assertSame(0, $this->db->fetchOne('SELECT COUNT(*) FROM beta.probe_recorded WHERE event_id = ?', ['Alpha-'.$event->eventId]));
            $this->db->executeStatement('SELECT pg_advisory_unlock(?)', [$lock]);
            $locked = false;
            self::assertSame(0, $first->wait(), $first->getErrorOutput());
            self::assertSame(0, $second->wait(), $second->getErrorOutput());
            $this->assertOneReactionEach($event);
            self::assertCount(1, $this->nextFacts($event));
            self::assertSame(0, $this->countQueue('async'));
        } finally {
            if ($locked) {
                $this->db->executeStatement('SELECT pg_advisory_unlock(?)', [$lock]);
            }
            $first->stop(0, SIGKILL);
            $second->stop(0, SIGKILL);
            $this->db->executeStatement('DROP TRIGGER hold_recording ON beta.probe_recorded');
            $this->db->executeStatement('DROP FUNCTION beta.hold_recording()');
            $one->clear();
            $two->clear();
        }
    }

    #[DataProvider('handlerOrders')]
    public function testFailedOrmSubscriberDoesNotUndoSuccessfulSubscriber(bool $failFirst): void
    {
        $event = $this->source();
        self::assertInstanceOf(ProbePublishedEvent::class, $event);
        $this->relay();
        if ($failFirst) {
            $this->kernel->shutdown();
            $this->bootMessagingKernel(FailFirstMessagingKernel::class);
        }
        $this->db->executeStatement("UPDATE beta.probe_control SET mode = 'orm'");
        $failedState = [];
        $listener = function () use ($event, &$failedState): void {
            $failedState = [
                'closed' => !$this->container->get('doctrine')->getManager()->isOpen(),
                'receipts' => $this->receipts($event),
                'next' => $this->nextFacts($event),
            ];
            $this->db->executeStatement("UPDATE beta.probe_control SET mode = 'none'");
        };
        $dispatcher = $this->container->get('event_dispatcher');
        $dispatcher->addListener(WorkerMessageFailedEvent::class, $listener, -200);
        try {
            $command = new CommandTester(new Application($this->kernel)->find('messenger:consume'));
            self::assertSame(0, $command->execute(['receivers' => ['async'], '--limit' => 2, '--time-limit' => 10, '--sleep' => 0.01], ['interactive' => false]));
        } finally {
            $dispatcher->removeListener(WorkerMessageFailedEvent::class, $listener);
        }
        self::assertTrue($failedState['closed']);
        self::assertSame($failFirst ? [] : ['beta.record-probe'], $failedState['receipts']);
        self::assertCount($failFirst ? 0 : 1, $failedState['next']);
        self::assertTrue($this->container->get('doctrine')->getManager()->isOpen());
        $this->assertOneReactionEach($event);
        self::assertSame(7, $this->db->fetchOne('SELECT value FROM beta.failure_probe WHERE id = ?', ['Alpha-'.$event->eventId]));
        self::assertCount(1, $this->nextFacts($event));
        $this->container->get('event.bus')->dispatch(new Envelope($event));
        self::assertNull($this->runOne()['failure']);
        $this->assertOneReactionEach($event);
        self::assertCount(1, $this->nextFacts($event));
    }

    /** @return iterable<string, array{bool}> */
    public static function handlerOrders(): iterable
    {
        yield 'successful first' => [false];
        yield 'ORM failure first' => [true];
    }

    public function testRelayFailureAndRestartPreserveEventIdentity(): void
    {
        $event = $this->source();
        self::assertInstanceOf(ProbePublishedEvent::class, $event);
        $snapshot = $this->db->fetchOne('SELECT body FROM shared.outbox_messages');
        $barrier = $this->barrier();
        $relay = $this->relayProcess('after-send', $barrier);
        $relay->start();
        try {
            $backend = (int) $barrier->wait('sent');
            self::assertSame($snapshot, $this->db->fetchOne('SELECT body FROM shared.outbox_messages'));
            self::assertSame(0, $this->countQueue('async'), 'Uncommitted transport insert must be invisible.');
            $relay->signal(SIGKILL);
            self::assertNotSame(0, $relay->wait());
            $this->waitForBackendExit($backend);
            self::assertSame(0, $this->db->fetchOne('SELECT attempts FROM shared.outbox_messages'));
            $restart = $this->relayProcess();
            self::assertSame(0, $restart->run(), $restart->getErrorOutput());
            self::assertEquals($event, $this->envelopes('async')[0]->getMessage());
            self::assertSame(0, $this->db->fetchOne('SELECT COUNT(*) FROM shared.outbox_messages'));
            self::assertNull($this->runOne()['failure']);
            $this->assertOneReactionEach($event);
        } finally {
            $relay->stop(0, SIGKILL);
            $barrier->clear();
        }
    }

    public function testConsumerPublishesNextFactAtomically(): void
    {
        $event = $this->source();
        self::assertInstanceOf(ProbePublishedEvent::class, $event);
        $this->relay();
        self::assertNull($this->runOne()['failure']);
        $this->assertOneReactionEach($event);
        $next = $this->nextFacts($event);
        self::assertCount(1, $next);
        self::assertSame(7, $next[0]->value);
        self::assertSame('Beta', $next[0]->source);
        self::assertSame('2026-10-26T09:00:00.654321Z', $next[0]->eventAt);
        $this->relay();
        self::assertEquals($next[0], $this->envelopes('async')[0]->getMessage());
        self::assertNull($this->runOne()['failure']);
        self::assertSame(0, $this->countQueue('async'));
        self::assertSame(0, $this->db->fetchOne('SELECT COUNT(*) FROM shared.outbox_messages'));
        $this->assertOneReactionEach($event);
    }

    private function barrier(): ProcessBarrier
    {
        $path = tempnam(sys_get_temp_dir(), 'messaging-acceptance-');
        self::assertIsString($path);
        unlink($path);

        return new ProcessBarrier($path);
    }

    private function consumerProcess(string $mode, ProcessBarrier $barrier): Process
    {
        return new Process([PHP_BINARY, dirname(__DIR__).'/Fixtures/Messaging/Integration/consumer-process.php', $mode, $barrier->path], timeout: 15);
    }

    private function relayProcess(string $mode = 'normal', ?ProcessBarrier $barrier = null): Process
    {
        return new Process([PHP_BINARY, dirname(__DIR__).'/Fixtures/Messaging/Integration/relay-process.php', $mode, $barrier->path ?? ''], timeout: 15);
    }

    private function waitForBackendExit(int $pid): void
    {
        $deadline = microtime(true) + 5;
        do {
            $connections = $this->db->fetchOne('SELECT COUNT(*) FROM pg_stat_activity WHERE pid = ?', [$pid]);
            if (0 === $connections) {
                self::assertSame(0, $connections, 'Killed worker connection has closed and its transaction has ended.');

                return;
            }
            usleep(10000);
        } while (microtime(true) < $deadline);
        self::fail('Killed worker backend is still connected: '.$pid);
    }

    private function waitForDatabaseLock(int $pid, string $expected): void
    {
        $deadline = microtime(true) + 5;
        do {
            if ($expected === $this->db->fetchOne('SELECT wait_event FROM pg_stat_activity WHERE pid = ?', [$pid])) {
                self::assertSame('Lock', $this->db->fetchOne('SELECT wait_event_type FROM pg_stat_activity WHERE pid = ?', [$pid]));

                return;
            }
            usleep(10000);
        } while (microtime(true) < $deadline);
        self::fail('Expected PostgreSQL '.$expected.' lock for worker '.$pid);
    }

    /** @return list<string> */
    private function receipts(ProbePublishedEvent $event): array
    {
        return $this->db->fetchFirstColumn('SELECT consumer_id FROM shared.processed_messages WHERE namespace = ? AND message_id = ? ORDER BY consumer_id', [$event->source, $event->eventId]);
    }

    private function assertOneReactionEach(ProbePublishedEvent $event): void
    {
        self::assertSame(['beta.fail-probe', 'beta.record-probe'], $this->receipts($event));
        self::assertSame(1, $this->db->fetchOne('SELECT COUNT(*) FROM beta.probe_recorded WHERE event_id = ?', ['Alpha-'.$event->eventId]));
        self::assertSame(1, $this->db->fetchOne('SELECT COUNT(*) FROM beta.probe_failed_effects WHERE event_id = ?', ['Alpha-'.$event->eventId]));
    }

    /** @return list<ProbeRecordedEvent> */
    private function nextFacts(ProbePublishedEvent $event): array
    {
        $found = [];
        foreach ($this->db->fetchFirstColumn('SELECT body FROM shared.outbox_messages') as $body) {
            $fact = unserialize(base64_decode($body, true));
            if ($fact instanceof ProbeRecordedEvent && $fact->operationId === 'Alpha-'.$event->eventId) {
                $found[] = $fact;
            }
        }

        return $found;
    }
}
