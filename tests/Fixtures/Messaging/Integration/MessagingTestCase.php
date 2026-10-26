<?php

declare(strict_types=1);

namespace Tests\Fixtures\Messaging\Integration;

use App\Shared\Application\Bus\Command\CommandBusInterface;
use App\Shared\Infrastructure\Messaging\Outbox\Relay\OutboxRelay;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;
use Symfony\Component\Messenger\Event\WorkerMessageHandledEvent;
use Symfony\Component\Messenger\Event\WorkerMessageReceivedEvent;
use Symfony\Component\Messenger\Event\WorkerRunningEvent;
use Symfony\Component\Messenger\Transport\Serialization\PhpSerializer;
use Symfony\Component\Messenger\Worker;
use Tests\Fixtures\Modules\Alpha\Application\PublishProbeEventCommand;
use Tests\Fixtures\TransactionalMessagingKernel;

abstract class MessagingTestCase extends TestCase
{
    protected TransactionalMessagingKernel $kernel;
    protected ContainerInterface $container;
    protected Connection $db;

    protected function setUp(): void
    {
        if (!getenv('MESSAGING_TEST_DATABASE_URL')) {
            self::markTestSkipped('Dedicated Messaging PostgreSQL test database is not configured.');
        }
        $this->bootMessagingKernel();
        $guard = require dirname(__DIR__, 4).'/tools/test-database-guard.php';
        $guard('test', true, $this->db->getParams());
        $this->db->executeStatement('CREATE SCHEMA IF NOT EXISTS alpha');
        $this->db->executeStatement('CREATE SCHEMA IF NOT EXISTS beta');
        $this->db->executeStatement('CREATE TABLE IF NOT EXISTS alpha.txn_probe (value INTEGER NOT NULL)');
        $this->db->executeStatement('CREATE TABLE IF NOT EXISTS beta.probe_recorded (event_id TEXT NOT NULL, occurred_at TEXT NOT NULL, value INTEGER NOT NULL, items JSONB NOT NULL)');
        $this->db->executeStatement('DROP TRIGGER IF EXISTS hold_recording ON beta.probe_recorded');
        $this->db->executeStatement('DROP FUNCTION IF EXISTS beta.hold_recording()');
        $this->db->executeStatement('CREATE TABLE IF NOT EXISTS beta.probe_failed_effects (event_id TEXT NOT NULL, value INTEGER NOT NULL)');
        $this->db->executeStatement('CREATE TABLE IF NOT EXISTS beta.failure_probe (id TEXT PRIMARY KEY, value INTEGER NOT NULL CHECK (value >= 0))');
        $this->db->executeStatement('CREATE TABLE IF NOT EXISTS beta.probe_control (mode TEXT NOT NULL)');
        $this->db->executeStatement('DROP TRIGGER IF EXISTS reject_outbox_delete ON shared.outbox_messages');
        $this->db->executeStatement('DELETE FROM shared.outbox_messages');
        $this->db->executeStatement('DELETE FROM shared.processed_messages');
        $this->db->executeStatement('DROP TRIGGER IF EXISTS reject_async ON public.messenger_messages');
        $this->db->executeStatement('DROP FUNCTION IF EXISTS public.reject_async()');
        $this->db->executeStatement('DELETE FROM public.messenger_messages');
        foreach (['alpha.txn_probe', 'beta.probe_recorded', 'beta.probe_failed_effects', 'beta.failure_probe', 'beta.probe_control'] as $table) {
            $this->db->executeStatement('DELETE FROM '.$table);
        }
        $this->db->executeStatement("INSERT INTO beta.probe_control (mode) VALUES ('none')");
    }

    protected function tearDown(): void
    {
        if (isset($this->db)) {
            while ($this->db->isTransactionActive()) {
                $this->db->rollBack();
            }
            $this->db->executeStatement('DROP TRIGGER IF EXISTS reject_outbox_delete ON shared.outbox_messages');
        }
        if (isset($this->kernel)) {
            $this->kernel->shutdown();
        }
    }

    /** @param class-string<TransactionalMessagingKernel> $class */
    protected function bootMessagingKernel(string $class = TransactionalMessagingKernel::class): void
    {
        $this->kernel = new $class('test', true);
        $this->kernel->boot();
        $this->container = $this->kernel->getContainer()->get('test.service_container');
        $this->db = $this->container->get(EntityManagerInterface::class)->getConnection();
        $this->db->executeStatement('SET search_path TO alpha, public');
    }

    protected function source(int $value = 7): object
    {
        $this->container->get(CommandBusInterface::class)->dispatch(new PublishProbeEventCommand($value));

        return unserialize(base64_decode($this->db->fetchOne('SELECT body FROM shared.outbox_messages ORDER BY id LIMIT 1'), true));
    }

    protected function relay(): void
    {
        self::assertTrue($this->container->get(OutboxRelay::class)->relayOne());
    }

    protected function observer(): Connection
    {
        return DriverManager::getConnection($this->db->getParams());
    }

    protected function countQueue(string $queue): int
    {
        return (int) $this->db->fetchOne('SELECT COUNT(*) FROM public.messenger_messages WHERE queue_name = ?', [$queue]);
    }

    /** @return list<Envelope> */
    protected function envelopes(string $queue): array
    {
        $serializer = new PhpSerializer();

        return array_map(static fn (array $row): Envelope => $serializer->decode(['body' => $row['body'], 'headers' => json_decode($row['headers'], true, flags: JSON_THROW_ON_ERROR)]), $this->db->fetchAllAssociative('SELECT body, headers FROM public.messenger_messages WHERE queue_name = ? ORDER BY id', [$queue]));
    }

    /** @return array{envelope: Envelope, failure: ?\Throwable, retry: bool} */
    protected function runOne(string $queue = 'async'): array
    {
        $dispatcher = $this->container->get('event_dispatcher');
        $worker = new Worker([$queue => $this->container->get('messenger.transport.'.$queue)], $this->container->get('messenger.routable_message_bus'), $dispatcher);
        $received = $failure = null;
        $retry = false;
        $onReceived = static function (WorkerMessageReceivedEvent $event) use (&$received): void { $received = $event->getEnvelope(); };
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
        self::assertInstanceOf(Envelope::class, $received, 'Expected an available message on '.$queue);

        return ['envelope' => $received, 'failure' => $failure, 'retry' => $retry];
    }
}
