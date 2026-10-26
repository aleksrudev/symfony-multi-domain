<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Shared\Application\Bus\Outbox\OutboxBusInterface;
use App\Shared\Infrastructure\Messaging\IntegrationEvent\IntegrationEventMessage;
use DAMA\DoctrineTestBundle\PHPUnit\SkipDatabaseRollback;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Stamp\BusNameStamp;
use Symfony\Component\Messenger\Stamp\ReceivedStamp;
use Symfony\Component\Messenger\Transport\Serialization\PhpSerializer;
use Symfony\Component\Uid\Uuid;
use Tests\Fixtures\Modules\Alpha\Presentation\Contracts\Dto\ProbeItem;
use Tests\Fixtures\Modules\Alpha\Presentation\Contracts\Event\ProbePublishedEvent;
use Tests\Fixtures\Modules\Beta\Application\Message\FailProbeMessage;
use Tests\Fixtures\Modules\Beta\Application\Message\RecordProbeMessage;
use Tests\Fixtures\PostgresIntegrationEventsKernel;

final class PostgresIntegrationEventTest extends TestCase
{
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    #[SkipDatabaseRollback]
    public function testSourceOutboxAndInboxHaveSeparateAtomicTransactionBoundaries(): void
    {
        if (!getenv('OUTBOX_TEST_DATABASE_URL')) {
            self::markTestSkipped('Dedicated PostgreSQL test database is not configured.');
        }
        $kernel = new PostgresIntegrationEventsKernel('test', true);
        $db = $observer = null;
        $eventIds = [];
        try {
            $kernel->boot();
            $container = $kernel->getContainer()->get('test.service_container');
            $db = $container->get(EntityManagerInterface::class)->getConnection();
            $guard = require dirname(__DIR__, 2).'/tools/test-database-guard.php';
            $guard('test', true, $db->getParams());
            $db->executeStatement('CREATE SCHEMA IF NOT EXISTS alpha');
            $db->executeStatement('CREATE TABLE IF NOT EXISTS alpha.local_event_probe (event_id VARCHAR(36) PRIMARY KEY, value INTEGER NOT NULL)');
            $observer = DriverManager::getConnection($db->getParams());
            $outbox = $container->get(OutboxBusInterface::class);
            self::assertInstanceOf(OutboxBusInterface::class, $outbox);
            $event = new ProbePublishedEvent(7, [new ProbeItem('item-1', 3)], Uuid::v4()->toRfc4122(), '2026-10-22T09:30:00.123456Z');
            $eventIds[] = $event->eventId;

            $db->beginTransaction();
            $db->insert('alpha.local_event_probe', ['event_id' => $event->eventId, 'value' => 7]);
            $outbox->publish(new IntegrationEventMessage($event));
            self::assertCount(1, $this->messageIds($db, $event->eventId, 'outbox'));
            self::assertSame([], $this->messageIds($observer, $event->eventId));
            self::assertSame(0, $observer->fetchOne('SELECT COUNT(*) FROM alpha.local_event_probe WHERE event_id = ?', [$event->eventId]));
            $db->commit();
            self::assertCount(1, $this->messageIds($observer, $event->eventId, 'outbox'));
            self::assertSame(1, $observer->fetchOne('SELECT COUNT(*) FROM alpha.local_event_probe WHERE event_id = ?', [$event->eventId]));

            $received = new Envelope(new IntegrationEventMessage($event), [new BusNameStamp('outbox.bus'), new ReceivedStamp('outbox')]);
            $db->beginTransaction();
            $container->get('messenger.routable_message_bus')->dispatch($received);
            self::assertCount(2, $this->messageIds($db, $event->eventId, 'async'));
            self::assertSame([], $this->messageIds($observer, $event->eventId, 'async'));
            $db->commit();
            self::assertCount(2, $this->messageIds($observer, $event->eventId, 'async'));

            $db->beginTransaction();
            $container->get('messenger.routable_message_bus')->dispatch($received);
            self::assertCount(4, $this->messageIds($db, $event->eventId, 'async'));
            self::assertCount(2, $this->messageIds($observer, $event->eventId, 'async'));
            $db->rollBack();
            self::assertCount(2, $this->messageIds($observer, $event->eventId, 'async'));
            self::assertSame(1, $observer->fetchOne('SELECT COUNT(*) FROM alpha.local_event_probe WHERE event_id = ?', [$event->eventId]));

            $rolledBack = new ProbePublishedEvent(8, [], Uuid::v4()->toRfc4122(), $event->eventAt);
            $eventIds[] = $rolledBack->eventId;
            $db->beginTransaction();
            $db->insert('alpha.local_event_probe', ['event_id' => $rolledBack->eventId, 'value' => 8]);
            $outbox->publish(new IntegrationEventMessage($rolledBack));
            self::assertCount(1, $this->messageIds($db, $rolledBack->eventId, 'outbox'));
            $db->rollBack();
            self::assertSame([], $this->messageIds($observer, $rolledBack->eventId));
            self::assertSame(0, $observer->fetchOne('SELECT COUNT(*) FROM alpha.local_event_probe WHERE event_id = ?', [$rolledBack->eventId]));
        } finally {
            while (null !== $db && $db->isTransactionActive()) {
                $db->rollBack();
            }
            if (null !== $observer) {
                foreach ($eventIds as $eventId) {
                    foreach ($this->messageIds($observer, $eventId) as $id) {
                        $observer->executeStatement('DELETE FROM public.messenger_messages WHERE id = ?', [$id]);
                    }
                    $observer->executeStatement('DELETE FROM alpha.local_event_probe WHERE event_id = ?', [$eventId]);
                }
                $observer->close();
            }
            $kernel->shutdown();
        }
    }

    /** @return list<string> */
    private function messageIds(Connection $db, string $eventId, ?string $queue = null): array
    {
        $ids = [];
        $serializer = new PhpSerializer();
        $sql = 'SELECT id, body, headers FROM public.messenger_messages';
        $rows = $db->fetchAllAssociative($sql.(null === $queue ? '' : ' WHERE queue_name = ?').' ORDER BY id', null === $queue ? [] : [$queue]);
        foreach ($rows as $row) {
            $message = $serializer->decode(['body' => (string) $row['body'], 'headers' => json_decode((string) $row['headers'], true, flags: JSON_THROW_ON_ERROR)])->getMessage();
            if ($message instanceof IntegrationEventMessage && $message->event instanceof ProbePublishedEvent) {
                $message = $message->event;
            }
            if (($message instanceof ProbePublishedEvent || $message instanceof RecordProbeMessage || $message instanceof FailProbeMessage) && $message->eventId === $eventId) {
                $ids[] = (string) $row['id'];
            }
        }

        return $ids;
    }
}
