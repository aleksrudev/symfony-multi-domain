<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Kernel;
use App\Shared\Application\Bus\Command\CommandBusInterface;
use App\Shared\Application\Bus\Query\QueryBusInterface;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Tests\Fixtures\ModuleKernel;
use Tests\Fixtures\Modules\Alpha\Application\ProbeCommand;
use Tests\Fixtures\Modules\Alpha\Application\ProbeQuery;
use Tests\Fixtures\Modules\Alpha\Domain\Entity\Record;

final class ModuleKernelTest extends TestCase
{
    public function testModuleWiringRoutesMappingAndEnvironment(): void
    {
        $kernel = new ModuleKernel('test', true);
        try {
            $kernel->boot();
            $container = $kernel->getContainer()->get('test.service_container');
            self::assertSame('module-test', $container->getParameter('alpha.origin'));
            self::assertSame(['error' => 'alpha.probe', 'status' => 409], $container->get(\App\Shared\Presentation\Http\Error\DomainErrorRegistry::class)->find(new \Tests\Fixtures\Modules\Alpha\Domain\ProbeFailure()));
            self::assertSame('/api/v1/probe', $container->get('router')->generate('alpha_probe'));
            self::assertSame('alpha', $container->get(EntityManagerInterface::class)->getClassMetadata(Record::class)->getSchemaName());
            $container->get(EntityManagerInterface::class)->getConnection()->executeStatement('CREATE TABLE txn_probe (value INTEGER NOT NULL)');
            $container->get(CommandBusInterface::class)->dispatch(new ProbeCommand());
            self::assertSame(1, $container->get(QueryBusInterface::class)->dispatch(new ProbeQuery()));
        } finally {
            $kernel->shutdown();
        }
    }

    public function testLocalScheduledCommandUsesItsOwnBus(): void
    {
        $kernel = new ModuleKernel('test', true);
        try {
            $kernel->boot();
            $container = $kernel->getContainer()->get('test.service_container');
            $db = $container->get(EntityManagerInterface::class)->getConnection();
            $db->executeStatement('CREATE TABLE txn_probe (value INTEGER NOT NULL)');
            $recurring = $container->get(\App\Shared\Infrastructure\Schedule\Schedule::class)->getSchedule()->getRecurringMessages();
            self::assertCount(1, $recurring);
            $task = array_values($recurring)[0];
            $context = new \Symfony\Component\Scheduler\Generator\MessageContext('default', 'probe', $task->getTrigger(), new \DateTimeImmutable());
            foreach ($task->getMessages($context) as $message) {
                self::assertInstanceOf(\Symfony\Component\Messenger\Envelope::class, $message);
                self::assertSame('command.bus', $message->last(\Symfony\Component\Messenger\Stamp\BusNameStamp::class)?->getBusName());
                $container->get('messenger.routable_message_bus')->dispatch($message);
            }
            self::assertSame(1, $container->get(QueryBusInterface::class)->dispatch(new ProbeQuery()));
            self::assertSame(1, $db->fetchOne('SELECT COUNT(*) FROM txn_probe'));
            self::assertCount(0, $container->get('messenger.transport.async')->getSent());
        } finally {
            $kernel->shutdown();
        }
    }

    public function testCommandValidationAndTransactionRollback(): void
    {
        $kernel = new ModuleKernel('test', true);
        try {
            $kernel->boot();
            $container = $kernel->getContainer()->get('test.service_container');
            $db = $container->get(EntityManagerInterface::class)->getConnection();
            $db->executeStatement('CREATE TABLE txn_probe (value INTEGER NOT NULL)');
            $bus = $container->get(CommandBusInterface::class);
            $bus->dispatch(new ProbeCommand());
            self::assertSame(1, $db->fetchOne('SELECT COUNT(*) FROM txn_probe'));
            try {
                $bus->dispatch(new ProbeCommand(fail: true));
                self::fail('Expected domain failure after write.');
            } catch (\Tests\Fixtures\Modules\Alpha\Domain\ProbeFailure) {
                self::assertSame(1, $db->fetchOne('SELECT COUNT(*) FROM txn_probe'));
                self::assertFalse($db->isTransactionActive());
            }
            try {
                $bus->dispatch(new ProbeCommand(value: 0));
                self::fail('Expected validation failure.');
            } catch (\Symfony\Component\Messenger\Exception\ValidationFailedException) {
                self::assertSame(1, $db->fetchOne('SELECT COUNT(*) FROM txn_probe'));
            }
        } finally {
            $kernel->shutdown();
        }
    }

    public function testPublicEventIsRoutedAsynchronouslyWithoutCallingLocalCommands(): void
    {
        $kernel = new ModuleKernel('test', true);
        try {
            $kernel->boot();
            $container = $kernel->getContainer()->get('test.service_container');
            $event = new \Tests\Fixtures\Modules\Alpha\Presentation\Contracts\Event\ProbePublishedEvent(7, [], 'event-1', '2026-10-26T09:00:00.123456Z');
            $container->get('event.bus')->dispatch($event);
            $sent = $container->get('messenger.transport.async')->getSent();
            self::assertCount(1, $sent);
            self::assertSame($event, $sent[0]->getMessage());
            self::assertSame('event.bus', $sent[0]->last(\Symfony\Component\Messenger\Stamp\BusNameStamp::class)?->getBusName());
            self::assertSame(0, $container->get(QueryBusInterface::class)->dispatch(new ProbeQuery()));
            self::assertFalse($container->has('inbox.bus'));
            self::assertFalse($container->has('outbox.bus'));
            self::assertFalse($container->has('messenger.transport.outbox'));
        } finally {
            $kernel->shutdown();
        }
    }

    public function testRetryLimitAndFailureTransport(): void
    {
        $kernel = new ModuleKernel('test', true);
        try {
            $kernel->boot();
            $container = $kernel->getContainer()->get('test.service_container');
            $envelope = new \Symfony\Component\Messenger\Envelope(new \Tests\Fixtures\Modules\Alpha\Presentation\Contracts\Event\ProbePublishedEvent(7, [], 'event-1', '2026-10-26T09:00:00.123456Z'));
            $strategy = $container->get('messenger.retry.multiplier_retry_strategy.async');
            self::assertTrue($strategy->isRetryable($envelope->with(new \Symfony\Component\Messenger\Stamp\RedeliveryStamp(2))));
            $exhausted = $envelope->with(new \Symfony\Component\Messenger\Stamp\RedeliveryStamp(3));
            self::assertFalse($strategy->isRetryable($exhausted));
            $event = new \Symfony\Component\Messenger\Event\WorkerMessageFailedEvent($exhausted, 'async', new \RuntimeException('fixture failure'));
            $container->get('event_dispatcher')->dispatch($event);
            self::assertFalse($event->willRetry());
            self::assertCount(1, $container->get('messenger.transport.failed')->getSent());
            self::assertCount(0, $container->get('messenger.transport.async')->getSent());
        } finally {
            $kernel->shutdown();
        }
    }

    public function testApplicationHasNoFixtureModules(): void
    {
        $kernel = new Kernel('test', true);
        try {
            $kernel->boot();
            $container = $kernel->getContainer()->get('test.service_container');
            self::assertFalse($container->hasParameter('alpha.origin'));
            self::assertCount(0, $container->get(\App\Shared\Infrastructure\Schedule\Schedule::class)->getSchedule()->getRecurringMessages());
            self::assertNull($container->get('router')->getRouteCollection()->get('alpha_probe'));
            self::assertSame([], $container->get(EntityManagerInterface::class)->getMetadataFactory()->getAllMetadata());
        } finally {
            $kernel->shutdown();
        }
    }

    public function testProductionRetryPolicyDelaysExponentiallyAndStopsAfterThreeRetries(): void
    {
        $kernel = new ModuleKernel('test', true);
        try {
            $kernel->boot();
            $strategy = $kernel->getContainer()->get('test.service_container')->get('messenger.retry.multiplier_retry_strategy.async');
            $event = new \Tests\Fixtures\Modules\Alpha\Presentation\Contracts\Event\ProbePublishedEvent(7, [], 'retry-policy', '2026-10-26T09:00:00.123456Z');
            foreach ([[900, 1100], [2700, 3300], [8100, 9900]] as $retry => [$minimum, $maximum]) {
                $envelope = new \Symfony\Component\Messenger\Envelope($event, [new \Symfony\Component\Messenger\Stamp\RedeliveryStamp($retry)]);
                self::assertTrue($strategy->isRetryable($envelope));
                $delay = $strategy->getWaitingTime($envelope);
                self::assertGreaterThanOrEqual($minimum, $delay);
                self::assertLessThanOrEqual($maximum, $delay);
            }
            self::assertFalse($strategy->isRetryable(new \Symfony\Component\Messenger\Envelope($event, [new \Symfony\Component\Messenger\Stamp\RedeliveryStamp(3)])));
            self::assertSame(30000, $strategy->getWaitingTime(new \Symfony\Component\Messenger\Envelope($event, [new \Symfony\Component\Messenger\Stamp\RedeliveryStamp(10)])));
        } finally {
            $kernel->shutdown();
        }
    }
}
