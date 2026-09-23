<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Shared\Application\Bus\Command\CommandBusInterface;
use App\Shared\Application\Bus\Outbox\OutboxBusInterface;
use App\Shared\Application\Bus\Outbox\OutboxMessageInterface;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;
use Symfony\Component\Messenger\Event\WorkerMessageHandledEvent;
use Symfony\Component\Messenger\Event\WorkerMessageReceivedEvent;
use Symfony\Component\Messenger\Stamp\BusNameStamp;
use Symfony\Component\Messenger\Stamp\ReceivedStamp;
use Symfony\Component\Messenger\Worker;
use Tests\Fixtures\ModuleKernel;
use Tests\Fixtures\Modules\Alpha\Application\ProbeCommand;
use Tests\Fixtures\Modules\Alpha\Application\ProbeHandler;
use Tests\Fixtures\Modules\Alpha\Domain\ProbeFailure;

final class OutboxBusTest extends TestCase
{
    public function testPublishingOutsideTransactionIsRejected(): void
    {
        $kernel = new ModuleKernel('test', true);
        try {
            $kernel->boot();
            $container = $kernel->getContainer()->get('test.service_container');
            $bus = $container->get(OutboxBusInterface::class);

            $this->expectException(\LogicException::class);
            $bus->publish(new class implements OutboxMessageInterface {});
        } finally {
            $kernel->shutdown();
        }
    }

    public function testCommandCommitsDataAndQueuedMessageTogether(): void
    {
        $kernel = new ModuleKernel('test', true);
        try {
            $kernel->boot();
            $container = $kernel->getContainer()->get('test.service_container');
            $db = $container->get(EntityManagerInterface::class)->getConnection();
            $this->createTables($db);

            $container->get(CommandBusInterface::class)->dispatch(new ProbeCommand(value: 7, publish: true));

            self::assertSame(1, $db->fetchOne('SELECT COUNT(*) FROM txn_probe'));
            self::assertSame(1, $db->fetchOne("SELECT COUNT(*) FROM messenger_messages WHERE queue_name = 'outbox'"));
            $transport = $container->get('messenger.transport.outbox');
            $dispatcher = $container->get('event_dispatcher');
            $worker = new Worker(['outbox' => $transport], $container->get('messenger.routable_message_bus'), $dispatcher);
            $envelope = null;
            $failure = null;
            $dispatcher->addListener(WorkerMessageReceivedEvent::class, static function (WorkerMessageReceivedEvent $event) use (&$envelope): void {
                $envelope = $event->getEnvelope();
            });
            $dispatcher->addListener(WorkerMessageHandledEvent::class, static function () use ($worker): void {
                $worker->stop();
            });
            $dispatcher->addListener(WorkerMessageFailedEvent::class, static function (WorkerMessageFailedEvent $event) use ($worker, &$failure): void {
                $failure = $event->getThrowable();
                $worker->stop();
            });
            $worker->run(['sleep' => 0]);
            self::assertNull($failure);
            self::assertNotNull($envelope);
            self::assertSame('outbox.bus', $envelope->last(BusNameStamp::class)?->getBusName());
            self::assertSame(1, $container->get(ProbeHandler::class)->outboxCalls);
            self::assertSame(7, $container->get(ProbeHandler::class)->outboxValue);
            self::assertSame(0, $db->fetchOne("SELECT COUNT(*) FROM messenger_messages WHERE queue_name = 'outbox'"));
            $container->get('messenger.routable_message_bus')->dispatch($envelope->with(new ReceivedStamp('outbox')));
            self::assertSame(1, $container->get(ProbeHandler::class)->outboxCalls);
        } finally {
            $kernel->shutdown();
        }
    }

    public function testCommandRollbackRemovesDataAndQueuedMessage(): void
    {
        $kernel = new ModuleKernel('test', true);
        try {
            $kernel->boot();
            $container = $kernel->getContainer()->get('test.service_container');
            $db = $container->get(EntityManagerInterface::class)->getConnection();
            $this->createTables($db);

            try {
                $container->get(CommandBusInterface::class)->dispatch(new ProbeCommand(value: 7, fail: true, publish: true));
                self::fail('Expected failure after publishing.');
            } catch (ProbeFailure) {
                self::assertSame(0, $db->fetchOne('SELECT COUNT(*) FROM txn_probe'));
                self::assertSame(0, $db->fetchOne("SELECT COUNT(*) FROM messenger_messages WHERE queue_name = 'outbox'"));
            }
        } finally {
            $kernel->shutdown();
        }
    }

    public function testExhaustedOutboxMessageMovesToFailedTransport(): void
    {
        $kernel = new ModuleKernel('test', true);
        try {
            $kernel->boot();
            $container = $kernel->getContainer()->get('test.service_container');
            $db = $container->get(EntityManagerInterface::class)->getConnection();
            $this->createTables($db);
            $db->beginTransaction();
            try {
                $container->get(OutboxBusInterface::class)->publish(new \Tests\Fixtures\Modules\Alpha\Application\ProbeOutbox(7));
                $db->commit();
            } catch (\Throwable $exception) {
                $db->rollBack();
                throw $exception;
            }
            $transport = $container->get('messenger.transport.outbox');
            $envelope = iterator_to_array($transport->get())[0];
            $strategy = $container->get('messenger.retry.multiplier_retry_strategy.outbox');
            self::assertTrue($strategy->isRetryable($envelope->with(new \Symfony\Component\Messenger\Stamp\RedeliveryStamp(2))));
            $exhausted = $envelope->with(new \Symfony\Component\Messenger\Stamp\RedeliveryStamp(3));
            self::assertFalse($strategy->isRetryable($exhausted));
            $event = new WorkerMessageFailedEvent($exhausted, 'outbox', new \RuntimeException('fixture failure'));
            $container->get('event_dispatcher')->dispatch($event);
            self::assertFalse($event->willRetry());
            self::assertCount(1, $container->get('messenger.transport.failed')->getSent());
            $transport->ack($envelope);
            self::assertSame(0, $db->fetchOne("SELECT COUNT(*) FROM messenger_messages WHERE queue_name = 'outbox'"));
        } finally {
            $kernel->shutdown();
        }
    }

    private function createTables(\Doctrine\DBAL\Connection $db): void
    {
        $db->executeStatement('CREATE TABLE txn_probe (value INTEGER NOT NULL)');
        $db->executeStatement('CREATE TABLE outbox_processed (value INTEGER PRIMARY KEY)');
        $db->executeStatement('CREATE TABLE messenger_messages (id INTEGER PRIMARY KEY AUTOINCREMENT, body TEXT NOT NULL, headers TEXT NOT NULL, queue_name VARCHAR(190) NOT NULL, created_at TIMESTAMP NOT NULL, available_at TIMESTAMP NOT NULL, delivered_at TIMESTAMP DEFAULT NULL)');
    }
}
