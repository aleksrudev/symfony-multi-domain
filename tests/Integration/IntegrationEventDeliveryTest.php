<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Shared\Application\Bus\Command\CommandBusInterface;
use App\Shared\Infrastructure\Messaging\IntegrationEvent\IntegrationEventDispatcherInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
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

    public function testRetriesAndFailedRecoveryPreserveTheSuccessfulReaction(): void
    {
        $event = $this->source();
        $this->db->executeStatement("UPDATE beta.probe_control SET mode = 'runtime'");
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
            self::assertSame(0, $tester->execute(['receivers' => ['async'], '--limit' => 2, '--time-limit' => 10, '--sleep' => 1000], ['interactive' => false]));
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
}
