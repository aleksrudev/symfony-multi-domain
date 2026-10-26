<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Shared\Application\Bus\Command\CommandBusInterface;
use App\Shared\Infrastructure\Messaging\IntegrationEvent\IntegrationEventDispatcherInterface;
use App\Shared\Infrastructure\Messaging\Outbox\Publisher\OutboxPublisherInterface;
use App\Shared\Infrastructure\Messaging\Outbox\Relay\OutboxRelay;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Log\AbstractLogger;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;
use Symfony\Component\Messenger\Event\WorkerMessageHandledEvent;
use Symfony\Component\Messenger\Stamp\HandledStamp;
use Tests\Fixtures\Messaging\Integration\MessagingTestCase;
use Tests\Fixtures\Modules\Alpha\Application\PublishProbeEventCommand;
use Tests\Fixtures\Modules\Alpha\Domain\ProbeFailure;
use Tests\Fixtures\Modules\Alpha\Presentation\Contracts\Event\ProbePublishedEvent;
use Tests\Fixtures\NoSubscribersMessagingKernel;

final class IntegrationEventDeliveryTest extends MessagingTestCase
{
    public function testSourceCommitsBusinessAndSnapshotWithoutDeliveringToSubscribers(): void
    {
        $event = $this->source();
        self::assertInstanceOf(ProbePublishedEvent::class, $event);
        self::assertSame(1, $this->db->fetchOne('SELECT COUNT(*) FROM alpha.txn_probe'));
        self::assertSame(1, $this->db->fetchOne('SELECT COUNT(*) FROM shared.outbox_messages'));
        self::assertSame(0, $this->countQueue('async'));
        self::assertSame(0, $this->db->fetchOne('SELECT COUNT(*) FROM beta.probe_recorded'));
        self::assertSame('2026-10-22T09:30:00.123456Z', $event->eventAt);
        $this->relay();
        self::assertSame(0, $this->db->fetchOne('SELECT COUNT(*) FROM shared.outbox_messages'));
        self::assertSame(1, $this->countQueue('async'));
        $queued = $this->envelopes('async')[0]->getMessage();
        self::assertEquals($event, $queued);
        self::assertNotSame($event, $queued);
        self::assertSame('item-1', $queued->items[0]->code);
        self::assertSame(3, $queued->items[0]->quantity);
        self::assertNull($this->runOne()['failure']);
        self::assertSame(1, $this->db->fetchOne('SELECT COUNT(*) FROM beta.probe_recorded'));
        self::assertSame(1, $this->db->fetchOne('SELECT COUNT(*) FROM beta.probe_failed_effects'));
        self::assertSame(2, $this->db->fetchOne('SELECT COUNT(*) FROM shared.processed_messages'));
        $record = $this->db->fetchAssociative('SELECT * FROM beta.probe_recorded');
        self::assertSame('Alpha-'.$event->eventId, $record['event_id']);
        self::assertSame($event->eventAt, $record['occurred_at']);
        self::assertSame([['code' => 'item-1', 'quantity' => 3]], json_decode($record['items'], true, flags: JSON_THROW_ON_ERROR));
    }

    public function testDispatcherRequiresSourceTransaction(): void
    {
        $this->expectException(\LogicException::class);
        $this->container->get(IntegrationEventDispatcherInterface::class)->dispatch(new ProbePublishedEvent(7, [], 'event-1', '2026-10-26T09:00:00.123456Z'));
    }

    public function testFailureAfterPublicationRollsBackBusinessAndSnapshot(): void
    {
        try {
            $this->container->get(CommandBusInterface::class)->dispatch(new PublishProbeEventCommand(7, fail: true));
            self::fail('Expected source failure.');
        } catch (ProbeFailure) {
            self::assertSame(0, $this->db->fetchOne('SELECT COUNT(*) FROM alpha.txn_probe'));
            self::assertSame(0, $this->db->fetchOne('SELECT COUNT(*) FROM shared.outbox_messages'));
            self::assertSame(0, $this->countQueue('async'));
            self::assertFalse($this->db->isTransactionActive());
        }
    }

    public function testOutboxWriteFailureRollsBackSource(): void
    {
        // DDL remains inside the caller transaction and is restored by rollback.
        $this->db->beginTransaction();
        $this->db->executeStatement('ALTER TABLE shared.outbox_messages RENAME TO unavailable_outbox');
        try {
            $this->container->get(CommandBusInterface::class)->dispatch(new PublishProbeEventCommand(7));
            self::fail('Expected missing Outbox failure.');
        } catch (\Doctrine\DBAL\Exception\TableNotFoundException) {
            self::assertTrue($this->db->isTransactionActive());
        } finally {
            $this->db->rollBack();
        }
        self::assertSame(0, $this->db->fetchOne('SELECT COUNT(*) FROM alpha.txn_probe'));
        self::assertSame(0, $this->db->fetchOne('SELECT COUNT(*) FROM shared.outbox_messages'));
    }

    #[DataProvider('subscriberFailures')]
    public function testRetriesAndFailedRecoveryPreserveTheSuccessfulReaction(string $mode): void
    {
        $event = $this->source();
        $this->db->executeStatement('UPDATE beta.probe_control SET mode = ?', [$mode]);
        $this->relay();
        for ($attempt = 0; $attempt < 4; ++$attempt) {
            $result = $this->runOne();
            self::assertNotNull($result['failure']);
            self::assertSame($attempt < 3, $result['retry']);
            self::assertSame(1, $this->db->fetchOne('SELECT COUNT(*) FROM beta.probe_recorded'));
            self::assertSame(0, $this->db->fetchOne('SELECT COUNT(*) FROM beta.probe_failed_effects'));
            self::assertSame(1, $this->db->fetchOne('SELECT COUNT(*) FROM shared.processed_messages'));
            self::assertSame(1, $this->db->fetchOne('SELECT COUNT(*) FROM shared.outbox_messages'));
        }
        self::assertSame(0, $this->countQueue('async'));
        self::assertSame(1, $this->countQueue('failed'));
        self::assertEquals($event, $this->envelopes('failed')[0]->getMessage());
        $this->db->executeStatement("UPDATE beta.probe_control SET mode = 'none'");
        $tester = new CommandTester(new Application($this->kernel)->find('messenger:failed:retry'));
        self::assertSame(0, $tester->execute(['id' => [$this->db->fetchOne("SELECT id FROM public.messenger_messages WHERE queue_name = 'failed'")], '--force' => true], ['interactive' => false]));
        // failed:retry handles this failed event immediately through the routable bus.
        self::assertSame(0, $this->countQueue('async'));
        self::assertSame(0, $this->countQueue('failed'));
        self::assertSame(1, $this->db->fetchOne('SELECT COUNT(*) FROM beta.probe_recorded'));
        self::assertSame(1, $this->db->fetchOne('SELECT COUNT(*) FROM beta.probe_failed_effects'));
        self::assertSame(2, $this->db->fetchOne('SELECT COUNT(*) FROM shared.processed_messages'));
        self::assertSame(1, $this->db->fetchOne('SELECT COUNT(*) FROM shared.outbox_messages'));

        $fresh = new Envelope($event);
        self::assertNull($fresh->last(HandledStamp::class));
        $this->container->get('event.bus')->dispatch($fresh);
        self::assertNull($this->runOne()['failure']);
        self::assertSame(1, $this->db->fetchOne('SELECT COUNT(*) FROM beta.probe_recorded'));
        self::assertSame(1, $this->db->fetchOne('SELECT COUNT(*) FROM beta.probe_failed_effects'));
        self::assertSame(1, $this->db->fetchOne('SELECT COUNT(*) FROM shared.outbox_messages'));
    }

    /** @return iterable<string, array{string}> */
    public static function subscriberFailures(): iterable
    {
        yield 'runtime failure' => ['runtime'];
        yield 'ORM flush failure' => ['orm'];
    }

    public function testRealConsumeCommandResetsClosedEntityManagerBetweenRetries(): void
    {
        $this->source();
        $this->relay();
        $this->db->executeStatement("UPDATE beta.probe_control SET mode = 'orm'");
        $closed = false;
        $handled = 0;
        $dispatcher = $this->container->get('event_dispatcher');
        $onFailed = function () use (&$closed): void {
            $closed = !$this->container->get('doctrine')->getManager()->isOpen();
            $this->db->executeStatement("UPDATE beta.probe_control SET mode = 'none'");
        };
        $onHandled = static function () use (&$handled): void { ++$handled; };
        $dispatcher->addListener(WorkerMessageFailedEvent::class, $onFailed, -200);
        $dispatcher->addListener(WorkerMessageHandledEvent::class, $onHandled);
        try {
            $tester = new CommandTester(new Application($this->kernel)->find('messenger:consume'));
            self::assertSame(0, $tester->execute(['receivers' => ['async'], '--limit' => 2, '--time-limit' => 10, '--sleep' => 0.01], ['interactive' => false]));
        } finally {
            $dispatcher->removeListener(WorkerMessageFailedEvent::class, $onFailed);
            $dispatcher->removeListener(WorkerMessageHandledEvent::class, $onHandled);
        }
        self::assertTrue($closed, 'First ORM flush must close the manager.');
        self::assertSame(1, $handled);
        self::assertTrue($this->container->get('doctrine')->getManager()->isOpen());
        self::assertSame(2, $this->db->fetchOne('SELECT COUNT(*) FROM shared.processed_messages'));
        self::assertSame(1, $this->db->fetchOne('SELECT COUNT(*) FROM beta.probe_recorded'));
        self::assertSame(1, $this->db->fetchOne('SELECT COUNT(*) FROM beta.probe_failed_effects'));
        self::assertSame(1, $this->db->fetchOne('SELECT COUNT(*) FROM beta.failure_probe'));
    }

    public function testNoHandlersIsValid(): void
    {
        $this->kernel->shutdown();
        $this->bootMessagingKernel(NoSubscribersMessagingKernel::class);
        $this->source();
        $this->relay();
        self::assertNull($this->runOne()['failure']);
        self::assertSame(0, $this->countQueue('async'));
        self::assertSame(0, $this->db->fetchOne('SELECT COUNT(*) FROM shared.processed_messages'));
    }

    public function testHandlerAddedBeforeConsumptionReceivesQueuedEvent(): void
    {
        $this->kernel->shutdown();
        $this->bootMessagingKernel(NoSubscribersMessagingKernel::class);
        $this->source();
        $this->relay();
        $this->kernel->shutdown();
        $this->bootMessagingKernel();
        self::assertNull($this->runOne()['failure']);
        self::assertSame(2, $this->db->fetchOne('SELECT COUNT(*) FROM shared.processed_messages'));
    }

    public function testExhaustedRelayPreservesSnapshotAndRetryDeliversTheSameFact(): void
    {
        $event = $this->source(37);
        self::assertInstanceOf(ProbePublishedEvent::class, $event);
        $original = $this->db->fetchAssociative('SELECT * FROM shared.outbox_messages');
        $clock = new MockClock($original['available_at'].'+00:00');
        $logger = new class extends AbstractLogger {
            /** @var list<array{message: string, context: array<string, mixed>}> */
            public array $records = [];

            public function log($level, string|\Stringable $message, array $context = []): void
            {
                $this->records[] = ['message' => (string) $message, 'context' => $context];
            }
        };
        $relay = new OutboxRelay($this->db, $clock, $this->container->get(OutboxPublisherInterface::class), $logger);
        $this->db->executeStatement("CREATE OR REPLACE FUNCTION public.reject_async() RETURNS trigger LANGUAGE plpgsql AS 'BEGIN IF NEW.queue_name = ''async'' THEN RAISE EXCEPTION ''sensitive-event-payload''; END IF; RETURN NEW; END'");
        $this->db->executeStatement('CREATE TRIGGER reject_async BEFORE INSERT ON public.messenger_messages FOR EACH ROW EXECUTE FUNCTION public.reject_async()');
        try {
            foreach ([1, 3, 9, 0] as $index => $delay) {
                self::assertTrue($relay->relayOne());
                $row = $this->db->fetchAssociative('SELECT * FROM shared.outbox_messages WHERE id = ?', [$original['id']]);
                self::assertSame($index + 1, $row['attempts']);
                self::assertSame($original['body'], $row['body']);
                self::assertSame($original['created_at'], $row['created_at']);
                self::assertSame(0, $this->countQueue('async'));
                self::assertSame(0, $this->countQueue('failed'));
                $clock->sleep($delay);
            }
            self::assertNotNull($row['failed_at']);
            self::assertTrue(is_a($row['last_error'], \Throwable::class, true));
            self::assertCount(4, $logger->records);
            $recorded = serialize($logger->records);
            self::assertStringNotContainsString('sensitive-event-payload', $recorded);
            self::assertStringNotContainsString('item-1', $recorded);
            self::assertStringNotContainsString($event->eventId, $recorded);
            self::assertStringNotContainsString($original['body'], $recorded);
            $clock->modify('+20 years');
            self::assertFalse($relay->relayOne(), 'Failed snapshot must neither disappear nor retry automatically.');
            self::assertSame($original['body'], $this->db->fetchOne('SELECT body FROM shared.outbox_messages WHERE id = ?', [$original['id']]));
            self::assertSame(0, $this->db->fetchOne('SELECT COUNT(*) FROM shared.processed_messages WHERE namespace = ? AND message_id = ?', [$event->source, $event->eventId]));
            $this->db->executeStatement('DROP TRIGGER reject_async ON public.messenger_messages');
            $retry = new CommandTester(new Application($this->kernel)->find('app:outbox:retry'));
            self::assertSame(0, $retry->execute(['id' => (string) $original['id']]));
            $restored = $this->db->fetchAssociative('SELECT * FROM shared.outbox_messages WHERE id = ?', [$original['id']]);
            self::assertSame(0, $restored['attempts']);
            self::assertNull($restored['failed_at']);
            self::assertNull($restored['last_error']);
            self::assertSame($original['body'], $restored['body']);
            self::assertSame($original['created_at'], $restored['created_at']);
            $this->relay();
            self::assertEquals($event, $this->envelopes('async')[0]->getMessage());
            self::assertNull($this->runOne()['failure']);
            self::assertSame(['beta.fail-probe', 'beta.record-probe'], $this->db->fetchFirstColumn('SELECT consumer_id FROM shared.processed_messages WHERE namespace = ? AND message_id = ? ORDER BY consumer_id', [$event->source, $event->eventId]));
        } finally {
            $this->db->executeStatement('DROP TRIGGER IF EXISTS reject_async ON public.messenger_messages');
            $this->db->executeStatement('DROP FUNCTION public.reject_async()');
        }
    }
}
