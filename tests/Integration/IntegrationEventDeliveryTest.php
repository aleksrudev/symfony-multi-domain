<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Shared\Application\Bus\Command\CommandBusInterface;
use App\Shared\Infrastructure\Messaging\IntegrationEventEnvelope;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;
use Symfony\Component\Messenger\Event\WorkerMessageHandledEvent;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\Exception\TransportException;
use Symfony\Component\Messenger\Stamp\ReceivedStamp;
use Symfony\Component\Messenger\Worker;
use Tests\Fixtures\IntegrationEventsKernel;
use Tests\Fixtures\Modules\Alpha\Application\PublishProbeEventCommand;
use Tests\Fixtures\Modules\Alpha\Domain\ProbeFailure;
use Tests\Fixtures\Modules\Beta\Infrastructure\Integration\Alpha\FailingSubscriber;
use Tests\Fixtures\Modules\Beta\Infrastructure\Integration\Alpha\RecordingSubscriber;

final class IntegrationEventDeliveryTest extends TestCase
{
    public function testAttributesPublishSeparateSerializableSnapshotsBeforeAnySubscriberRuns(): void
    {
        $kernel = new IntegrationEventsKernel('test', true);
        try {
            $kernel->boot();
            $container = $kernel->getContainer()->get('test.service_container');
            $db = $container->get(EntityManagerInterface::class)->getConnection();
            $this->createTables($db);
            $container->get(CommandBusInterface::class)->dispatch(new PublishProbeEventCommand(7));

            self::assertSame(1, $db->fetchOne('SELECT COUNT(*) FROM txn_probe WHERE value = 7'));
            self::assertSame([], $container->get(RecordingSubscriber::class)->events);
            self::assertSame(2, $db->fetchOne("SELECT COUNT(*) FROM messenger_messages WHERE queue_name = 'outbox'"));
            $transport = $container->get('messenger.transport.outbox');
            $deliveries = [...iterator_to_array($transport->get()), ...iterator_to_array($transport->get())];
            self::assertCount(2, $deliveries);
            $messages = array_map(static fn ($envelope) => $envelope->getMessage(), $deliveries);
            $eventIds = [];
            foreach ($messages as $message) {
                self::assertInstanceOf(IntegrationEventEnvelope::class, $message);
                $eventIds[] = $message->metadata->eventId;
                self::assertSame('2026-10-22T09:30:00.123456Z', $message->metadata->eventAt);
                self::assertSame(['value' => 7, 'items' => [['code' => 'item-1', 'quantity' => 3]]], json_decode($message->payload, true, flags: JSON_THROW_ON_ERROR));
                self::assertStringNotContainsString('Tests\\Fixtures\\Modules', serialize($message));
            }
            self::assertCount(1, array_unique($eventIds));
            self::assertSame(['beta.record', 'beta.fail'], array_column($messages, 'subscriberId'));
        } finally {
            $kernel->shutdown();
        }
    }

    public function testFailedDeliveryDoesNotRepeatAnAlreadySuccessfulSubscriber(): void
    {
        $kernel = new IntegrationEventsKernel('test', true);
        try {
            $kernel->boot();
            $container = $kernel->getContainer()->get('test.service_container');
            $db = $container->get(EntityManagerInterface::class)->getConnection();
            $this->createTables($db);
            $container->get(CommandBusInterface::class)->dispatch(new PublishProbeEventCommand(7));
            $transport = $container->get('messenger.transport.outbox');
            $dispatcher = $container->get('event_dispatcher');
            $worker = new Worker(['outbox' => $transport], $container->get('messenger.routable_message_bus'), $dispatcher);
            $failure = null;
            $dispatcher->addListener(WorkerMessageHandledEvent::class, static function () use ($worker): void { $worker->stop(); });
            $dispatcher->addListener(WorkerMessageFailedEvent::class, static function (WorkerMessageFailedEvent $event) use ($worker, &$failure): void {
                $failure = $event->getThrowable();
                $worker->stop();
            });
            $worker->run(['sleep' => 0]);
            self::assertNull($failure);
            $recording = $container->get(RecordingSubscriber::class);
            self::assertCount(1, $recording->events);
            self::assertSame(3, $recording->events[0]->items[0]->quantity);
            self::assertSame(1, $db->fetchOne("SELECT COUNT(*) FROM messenger_messages WHERE queue_name = 'outbox'"));

            $remaining = iterator_to_array($transport->get());
            self::assertCount(1, $remaining);
            $delivery = $remaining[0];
            self::assertSame('beta.fail', $delivery->getMessage()->subscriberId);
            self::assertSame($recording->metadata[0]->eventId, $delivery->getMessage()->metadata->eventId);
            for ($attempt = 0; $attempt < 2; ++$attempt) {
                try {
                    $container->get('messenger.routable_message_bus')->dispatch($delivery->with(new ReceivedStamp('outbox')));
                    self::fail('Expected subscriber failure.');
                } catch (HandlerFailedException $exception) {
                    self::assertStringContainsString('Fixture subscriber is unavailable.', $exception->getMessage());
                }
            }
            self::assertCount(1, $recording->events);
            self::assertSame(2, $container->get(FailingSubscriber::class)->calls);
        } finally {
            $kernel->shutdown();
        }
    }

    public function testFailureAfterPublishingRollsBackSourceAndAllDeliveries(): void
    {
        $kernel = new IntegrationEventsKernel('test', true);
        try {
            $kernel->boot();
            $container = $kernel->getContainer()->get('test.service_container');
            $db = $container->get(EntityManagerInterface::class)->getConnection();
            $this->createTables($db);
            try {
                $container->get(CommandBusInterface::class)->dispatch(new PublishProbeEventCommand(7, fail: true));
                self::fail('Expected command failure.');
            } catch (ProbeFailure) {
                self::assertSame(0, $db->fetchOne('SELECT COUNT(*) FROM txn_probe WHERE value = 7'));
                self::assertSame(0, $db->fetchOne('SELECT COUNT(*) FROM messenger_messages'));
            }
        } finally {
            $kernel->shutdown();
        }
    }

    public function testOutboxWriteFailureRollsBackTheSourceChange(): void
    {
        $kernel = new IntegrationEventsKernel('test', true);
        try {
            $kernel->boot();
            $container = $kernel->getContainer()->get('test.service_container');
            $db = $container->get(EntityManagerInterface::class)->getConnection();
            $db->executeStatement('CREATE TABLE txn_probe (value INTEGER NOT NULL)');
            try {
                $container->get(CommandBusInterface::class)->dispatch(new PublishProbeEventCommand(7));
                self::fail('Expected missing outbox table failure.');
            } catch (TransportException $exception) {
                self::assertInstanceOf(\Doctrine\DBAL\Exception\TableNotFoundException::class, $exception->getPrevious());
                self::assertSame(0, $db->fetchOne('SELECT COUNT(*) FROM txn_probe WHERE value = 7'));
            }
        } finally {
            $kernel->shutdown();
        }
    }

    public function testSecondOutboxWriteFailureRollsBackTheFirstDeliveryToo(): void
    {
        $kernel = new IntegrationEventsKernel('test', true);
        try {
            $kernel->boot();
            $container = $kernel->getContainer()->get('test.service_container');
            $db = $container->get(EntityManagerInterface::class)->getConnection();
            $this->createTables($db);
            $db->executeStatement("CREATE TRIGGER fail_second_delivery BEFORE INSERT ON messenger_messages WHEN (SELECT COUNT(*) FROM messenger_messages) = 1 BEGIN SELECT RAISE(ABORT, 'second delivery failed'); END");
            try {
                $container->get(CommandBusInterface::class)->dispatch(new PublishProbeEventCommand(7));
                self::fail('Expected second delivery failure.');
            } catch (TransportException $exception) {
                self::assertStringContainsString('second delivery failed', $exception->getMessage());
                self::assertSame(0, $db->fetchOne('SELECT COUNT(*) FROM txn_probe WHERE value = 7'));
                self::assertSame(0, $db->fetchOne('SELECT COUNT(*) FROM messenger_messages'));
            }
        } finally {
            $kernel->shutdown();
        }
    }

    private function createTables(Connection $db): void
    {
        $db->executeStatement('CREATE TABLE txn_probe (value INTEGER NOT NULL)');
        $db->executeStatement('CREATE TABLE messenger_messages (id INTEGER PRIMARY KEY AUTOINCREMENT, body TEXT NOT NULL, headers TEXT NOT NULL, queue_name VARCHAR(190) NOT NULL, created_at TIMESTAMP NOT NULL, available_at TIMESTAMP NOT NULL, delivered_at TIMESTAMP DEFAULT NULL)');
    }
}
