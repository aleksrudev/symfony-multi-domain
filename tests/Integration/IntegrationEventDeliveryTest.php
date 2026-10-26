<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Shared\Application\Bus\Command\CommandBusInterface;
use App\Shared\Application\Bus\Outbox\OutboxBusInterface;
use App\Shared\Infrastructure\Messaging\IntegrationEvent\IntegrationEventMessage;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;
use Symfony\Component\Messenger\Event\WorkerMessageHandledEvent;
use Symfony\Component\Messenger\Event\WorkerMessageReceivedEvent;
use Symfony\Component\Messenger\Event\WorkerRunningEvent;
use Symfony\Component\Messenger\Exception\TransportException;
use Symfony\Component\Messenger\Stamp\BusNameStamp;
use Symfony\Component\Messenger\Transport\Serialization\PhpSerializer;
use Symfony\Component\Messenger\Worker;
use Symfony\Component\Uid\Uuid;
use Tests\Fixtures\IntegrationEventsKernel;
use Tests\Fixtures\Modules\Alpha\Application\PublishProbeEventCommand;
use Tests\Fixtures\Modules\Alpha\Domain\ProbeFailure;
use Tests\Fixtures\Modules\Alpha\Presentation\Contracts\Dto\ProbeItem;
use Tests\Fixtures\Modules\Alpha\Presentation\Contracts\Event\ProbePublishedEvent;
use Tests\Fixtures\Modules\Beta\Application\Message\FailProbeHandler;
use Tests\Fixtures\Modules\Beta\Application\Message\FailProbeMessage;
use Tests\Fixtures\Modules\Beta\Application\Message\RecordProbeHandler;
use Tests\Fixtures\Modules\Beta\Application\Message\RecordProbeMessage;

final class IntegrationEventDeliveryTest extends TestCase
{
    private IntegrationEventsKernel $kernel;
    private ContainerInterface $container;
    private Connection $db;

    protected function setUp(): void
    {
        $this->kernel = new IntegrationEventsKernel('test', true);
        $this->kernel->boot();
        $this->container = $this->kernel->getContainer()->get('test.service_container');
        $this->db = $this->container->get(EntityManagerInterface::class)->getConnection();
        $this->db->executeStatement('CREATE TABLE txn_probe (value INTEGER NOT NULL)');
        $this->db->executeStatement('CREATE TABLE probe_recorded (event_id VARCHAR(36) PRIMARY KEY, value INTEGER NOT NULL)');
        $this->db->executeStatement('CREATE TABLE probe_failed_effects (event_id VARCHAR(36) PRIMARY KEY, value INTEGER NOT NULL)');
        $this->db->executeStatement('CREATE TABLE messenger_messages (id INTEGER PRIMARY KEY AUTOINCREMENT, body TEXT NOT NULL, headers TEXT NOT NULL, queue_name VARCHAR(190) NOT NULL, created_at TIMESTAMP NOT NULL, available_at TIMESTAMP NOT NULL, delivered_at TIMESTAMP DEFAULT NULL)');
    }

    protected function tearDown(): void
    {
        if ($this->db->isTransactionActive()) {
            $this->db->rollBack();
        }
        $this->kernel->shutdown();
    }

    public function testPublishingQueuesOnePublicEventBeforeRecipientsRun(): void
    {
        $this->container->get(CommandBusInterface::class)->dispatch(new PublishProbeEventCommand(7));
        self::assertSame(1, $this->countQueue('outbox'));
        self::assertSame(0, $this->countQueue('async'));
        self::assertSame(1, $this->db->fetchOne('SELECT COUNT(*) FROM txn_probe WHERE value = 7'));
        self::assertSame(0, $this->container->get(RecordProbeHandler::class)->calls);
        self::assertSame(0, $this->container->get(FailProbeHandler::class)->calls);
        $event = $this->queuedPublicEvent();
        self::assertTrue(Uuid::isValid($event->eventId));
        self::assertSame('2026-10-22T09:30:00.123456Z', $event->eventAt);
        self::assertSame('Alpha', $event->source);
        self::assertSame('alpha.published', $event->type);
        self::assertSame(1, $event->schemaVersion);
        self::assertSame(3, $event->items[0]->quantity);
    }

    public function testPublishingOutsideTransactionIsRejectedEvenWithoutListeners(): void
    {
        $this->removeRecipients();
        $event = new ProbePublishedEvent(7, [], Uuid::v4()->toRfc4122(), '2026-10-22T09:30:00.123456Z');
        $this->expectException(\LogicException::class);
        $this->container->get(OutboxBusInterface::class)->publish(new IntegrationEventMessage($event));
    }

    public function testOutboxWorkerOnlyEnqueuesIndependentInboxSnapshots(): void
    {
        $this->container->get(CommandBusInterface::class)->dispatch(new PublishProbeEventCommand(7));
        $event = $this->queuedPublicEvent();
        $result = $this->runOne('outbox');
        self::assertNull($result['failure']);
        self::assertSame('outbox.bus', $result['envelope']->last(BusNameStamp::class)?->getBusName());
        self::assertSame(0, $this->countQueue('outbox'));
        self::assertSame(2, $this->countQueue('async'));
        self::assertSame(0, $this->container->get(RecordProbeHandler::class)->calls);
        self::assertSame(0, $this->container->get(FailProbeHandler::class)->calls);
        $messages = $this->messages('async');
        self::assertInstanceOf(RecordProbeMessage::class, $messages[0]);
        self::assertInstanceOf(FailProbeMessage::class, $messages[1]);
        foreach ($messages as $message) {
            self::assertTrue($message instanceof RecordProbeMessage || $message instanceof FailProbeMessage);
            $this->assertMetadataSame($event, $message);
            self::assertSame([['code' => 'item-1', 'quantity' => 3]], $message->items);
        }
    }

    public function testInboxRetriesAndFailedRecoveryDoNotRepeatTheSuccessfulReaction(): void
    {
        $this->container->get(CommandBusInterface::class)->dispatch(new PublishProbeEventCommand(7));
        $event = $this->queuedPublicEvent();
        self::assertNull($this->runOne('outbox')['failure']);
        $success = $this->runOne('async');
        self::assertNull($success['failure']);
        self::assertSame('inbox.bus', $success['envelope']->last(BusNameStamp::class)?->getBusName());
        $recording = $this->container->get(RecordProbeHandler::class);
        $failing = $this->container->get(FailProbeHandler::class);
        for ($attempt = 0; $attempt < 4; ++$attempt) {
            $result = $this->runOne('async');
            self::assertNotNull($result['failure']);
            self::assertStringContainsString('Fixture inbox handler is unavailable.', $result['failure']->getMessage());
            self::assertSame($attempt < 3, $result['retry']);
            self::assertSame(1, $recording->calls);
            self::assertSame(1, $this->db->fetchOne('SELECT COUNT(*) FROM probe_recorded'));
            self::assertSame(0, $this->db->fetchOne('SELECT COUNT(*) FROM probe_failed_effects'));
        }
        self::assertSame(4, $failing->calls);
        self::assertSame(0, $this->countQueue('async'));
        self::assertSame(1, $this->countQueue('failed'));
        $failed = $this->messages('failed')[0];
        self::assertInstanceOf(FailProbeMessage::class, $failed);
        $this->assertMetadataSame($event, $failed);

        $failing->fail = false;
        self::assertNull($this->runOne('failed')['failure']);
        self::assertSame(0, $this->countQueue('failed'));
        self::assertSame(1, $this->db->fetchOne('SELECT COUNT(*) FROM probe_failed_effects'));
        self::assertSame(1, $recording->calls);
        self::assertSame(5, $failing->calls);
        foreach ($failing->messages as $message) {
            $this->assertMetadataSame($event, $message);
        }
    }

    public function testFailureAfterPublishingRollsBackSourceAndOutbox(): void
    {
        try {
            $this->container->get(CommandBusInterface::class)->dispatch(new PublishProbeEventCommand(7, fail: true));
            self::fail('Expected command failure.');
        } catch (ProbeFailure) {
            self::assertSame(0, $this->db->fetchOne('SELECT COUNT(*) FROM txn_probe WHERE value = 7'));
            self::assertSame(0, $this->countQueue('outbox'));
            self::assertSame(0, $this->countQueue('async'));
        }
    }

    public function testOutboxWriteFailureRollsBackSource(): void
    {
        $this->db->executeStatement('DROP TABLE messenger_messages');
        try {
            $this->container->get(CommandBusInterface::class)->dispatch(new PublishProbeEventCommand(7));
            self::fail('Expected missing outbox table failure.');
        } catch (TransportException $exception) {
            self::assertInstanceOf(\Doctrine\DBAL\Exception\TableNotFoundException::class, $exception->getPrevious());
            self::assertSame(0, $this->db->fetchOne('SELECT COUNT(*) FROM txn_probe WHERE value = 7'));
        }
    }

    public function testSecondInboxWriteFailureRollsBackAllInboxWritesAndRetriesOnlyFanout(): void
    {
        $this->container->get(CommandBusInterface::class)->dispatch(new PublishProbeEventCommand(7));
        $event = $this->queuedPublicEvent();
        $this->db->executeStatement("CREATE TRIGGER fail_second_inbox BEFORE INSERT ON messenger_messages WHEN NEW.queue_name = 'async' AND (SELECT COUNT(*) FROM messenger_messages WHERE queue_name = 'async') = 1 BEGIN SELECT RAISE(ABORT, 'second inbox failed'); END");
        $failure = $this->runOne('outbox');
        self::assertNotNull($failure['failure']);
        self::assertStringContainsString('second inbox failed', $failure['failure']->getMessage());
        self::assertTrue($failure['retry']);
        self::assertSame(0, $this->countQueue('async'));
        self::assertSame(1, $this->countQueue('outbox'));
        self::assertSame(1, $this->db->fetchOne('SELECT COUNT(*) FROM txn_probe WHERE value = 7'));
        self::assertSame(0, $this->container->get(RecordProbeHandler::class)->calls);
        self::assertSame(0, $this->container->get(FailProbeHandler::class)->calls);
        self::assertEquals($event, $this->queuedPublicEvent());

        $this->db->executeStatement('DROP TRIGGER fail_second_inbox');
        self::assertNull($this->runOne('outbox')['failure']);
        self::assertSame(2, $this->countQueue('async'));
    }

    public function testNoListenersIsAValidDelivery(): void
    {
        $this->removeRecipients();
        $this->container->get(CommandBusInterface::class)->dispatch(new PublishProbeEventCommand(7));
        self::assertSame(1, $this->countQueue('outbox'));
        self::assertNull($this->runOne('outbox')['failure']);
        self::assertSame(0, $this->countQueue('outbox'));
        self::assertSame(0, $this->countQueue('async'));
    }

    public function testRedeliveredPublicEventPreservesIdentityAndAllowsConsumerDeduplication(): void
    {
        $this->container->get(FailProbeHandler::class)->fail = false;
        $this->container->get(CommandBusInterface::class)->dispatch(new PublishProbeEventCommand(7));
        $event = $this->queuedPublicEvent();
        for ($delivery = 0; $delivery < 2; ++$delivery) {
            if (1 === $delivery) {
                $this->db->beginTransaction();
                $this->container->get(OutboxBusInterface::class)->publish(new IntegrationEventMessage($event));
                $this->db->commit();
            }
            self::assertNull($this->runOne('outbox')['failure']);
            self::assertSame(2, $this->countQueue('async'));
            self::assertNull($this->runOne('async')['failure']);
            self::assertNull($this->runOne('async')['failure']);
            self::assertSame(1, $this->db->fetchOne('SELECT COUNT(*) FROM probe_recorded'));
            self::assertSame(1, $this->db->fetchOne('SELECT COUNT(*) FROM probe_failed_effects'));
        }
        $recording = $this->container->get(RecordProbeHandler::class);
        self::assertSame(2, $recording->calls);
        foreach ($recording->messages as $message) {
            $this->assertMetadataSame($event, $message);
            self::assertSame([['code' => 'item-1', 'quantity' => 3]], $message->items);
        }
    }

    public function testSerializationCreatesAnIndependentSnapshot(): void
    {
        $items = [new ProbeItem('item-1', 3)];
        $event = new ProbePublishedEvent(7, $items, Uuid::v4()->toRfc4122(), '2026-10-22T09:30:00.123456Z');
        $outbox = $this->container->get(OutboxBusInterface::class);
        self::assertInstanceOf(OutboxBusInterface::class, $outbox);
        $this->db->beginTransaction();
        $outbox->publish(new IntegrationEventMessage($event));
        $this->db->commit();
        $items[0] = new ProbeItem('changed', 99);
        $restored = $this->queuedPublicEvent();
        self::assertNotSame($event, $restored);
        self::assertNotSame($event->items[0], $restored->items[0]);
        self::assertSame('item-1', $restored->items[0]->code);
        self::assertSame(3, $restored->items[0]->quantity);
        self::assertEquals($event, $restored);
    }

    /** @return array{envelope: Envelope, failure: ?\Throwable, retry: bool} */
    private function runOne(string $queue): array
    {
        $dispatcher = $this->container->get('event_dispatcher');
        $worker = new Worker([$queue => $this->container->get('messenger.transport.'.$queue)], $this->container->get('messenger.routable_message_bus'), $dispatcher);
        $received = $failure = null;
        $retry = false;
        $onReceived = static function (WorkerMessageReceivedEvent $event) use (&$received): void {
            $received = $event->getEnvelope();
        };
        $onHandled = static function () use ($worker): void { $worker->stop(); };
        $onFailed = static function (WorkerMessageFailedEvent $event) use ($worker, &$failure, &$retry): void {
            $failure = $event->getThrowable();
            $retry = $event->willRetry();
            $worker->stop();
        };
        $onIdle = static function (WorkerRunningEvent $event) use ($worker): void {
            if ($event->isWorkerIdle()) {
                $worker->stop();
            }
        };
        $dispatcher->addListener(WorkerMessageReceivedEvent::class, $onReceived);
        $dispatcher->addListener(WorkerMessageHandledEvent::class, $onHandled);
        $dispatcher->addListener(WorkerMessageFailedEvent::class, $onFailed, -200);
        $dispatcher->addListener(WorkerRunningEvent::class, $onIdle);
        try {
            $worker->run(['sleep' => 0]);
        } finally {
            $dispatcher->removeListener(WorkerMessageReceivedEvent::class, $onReceived);
            $dispatcher->removeListener(WorkerMessageHandledEvent::class, $onHandled);
            $dispatcher->removeListener(WorkerMessageFailedEvent::class, $onFailed);
            $dispatcher->removeListener(WorkerRunningEvent::class, $onIdle);
        }
        self::assertInstanceOf(Envelope::class, $received, 'Expected an immediately available message on '.$queue);

        return ['envelope' => $received, 'failure' => $failure, 'retry' => $retry];
    }

    private function countQueue(string $queue): int
    {
        return (int) $this->db->fetchOne('SELECT COUNT(*) FROM messenger_messages WHERE queue_name = ?', [$queue]);
    }

    /** @return list<object> */
    private function messages(string $queue): array
    {
        $messages = [];
        $serializer = new PhpSerializer();
        foreach ($this->db->fetchAllAssociative('SELECT body, headers FROM messenger_messages WHERE queue_name = ? ORDER BY id', [$queue]) as $row) {
            $messages[] = $serializer->decode(['body' => (string) $row['body'], 'headers' => json_decode((string) $row['headers'], true, flags: JSON_THROW_ON_ERROR)])->getMessage();
        }

        return $messages;
    }

    private function queuedPublicEvent(): ProbePublishedEvent
    {
        $message = $this->messages('outbox')[0];
        self::assertInstanceOf(IntegrationEventMessage::class, $message);
        self::assertInstanceOf(ProbePublishedEvent::class, $message->event);

        return $message->event;
    }

    private function assertMetadataSame(ProbePublishedEvent $event, RecordProbeMessage|FailProbeMessage $message): void
    {
        self::assertSame($event->eventId, $message->eventId);
        self::assertSame($event->eventAt, $message->eventAt);
        self::assertSame($event->source, $message->source);
        self::assertSame($event->type, $message->type);
        self::assertSame($event->schemaVersion, $message->schemaVersion);
    }

    private function removeRecipients(): void
    {
        $dispatcher = $this->container->get('event_dispatcher');
        self::assertInstanceOf(EventDispatcherInterface::class, $dispatcher);
        foreach ($dispatcher->getListeners(ProbePublishedEvent::class) as $listener) {
            $dispatcher->removeListener(ProbePublishedEvent::class, $listener);
        }
    }
}
