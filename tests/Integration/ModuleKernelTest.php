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

    public function testInboxRoutingAndScheduleContributions(): void
    {
        $kernel = new ModuleKernel('test', true);
        try {
            $kernel->boot();
            $container = $kernel->getContainer()->get('test.service_container');
            $handler = $container->get(\Tests\Fixtures\Modules\Alpha\Application\ProbeHandler::class);
            $db = $container->get(EntityManagerInterface::class)->getConnection();
            $db->executeStatement('CREATE TABLE txn_probe (value INTEGER NOT NULL)');
            $container->get(\App\Shared\Application\Bus\Inbox\InboxBusInterface::class)->dispatch(new \Tests\Fixtures\Modules\Alpha\Application\ProbeInbox());
            $transport = $container->get('messenger.transport.async');
            self::assertCount(1, $transport->getSent());
            self::assertSame(0, $handler->calls);
            $envelope = $transport->getSent()[0];
            self::assertSame('inbox.bus', $envelope->last(\Symfony\Component\Messenger\Stamp\BusNameStamp::class)?->getBusName());
            $container->get('inbox.bus')->dispatch($envelope->with(new \Symfony\Component\Messenger\Stamp\ReceivedStamp('async')));
            self::assertSame(1, $handler->calls);
            $recurring = $container->get(\App\Shared\Infrastructure\Schedule\Schedule::class)->getSchedule()->getRecurringMessages();
            self::assertCount(1, $recurring);
            $task = array_values($recurring)[0];
            $context = new \Symfony\Component\Scheduler\Generator\MessageContext('default', 'probe', $task->getTrigger(), new \DateTimeImmutable());
            foreach ($task->getMessages($context) as $message) {
                $container->get('default.bus')->dispatch($message);
            }
            self::assertCount(2, $transport->getSent());
            $scheduledEnvelope = $transport->getSent()[1];
            self::assertSame('inbox.bus', $scheduledEnvelope->last(\Symfony\Component\Messenger\Stamp\BusNameStamp::class)?->getBusName());
            $container->get('messenger.routable_message_bus')->dispatch($scheduledEnvelope->with(new \Symfony\Component\Messenger\Stamp\ReceivedStamp('async')));
            self::assertSame(2, $handler->calls);
            self::assertSame(2, $db->fetchOne('SELECT COUNT(*) FROM txn_probe'));
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

    public function testReceivedInboxValidationAndTransactionRollback(): void
    {
        $kernel = new ModuleKernel('test', true);
        try {
            $kernel->boot();
            $container = $kernel->getContainer()->get('test.service_container');
            $db = $container->get(EntityManagerInterface::class)->getConnection();
            $db->executeStatement('CREATE TABLE txn_probe (value INTEGER NOT NULL)');
            $bus = $container->get('inbox.bus');
            $received = [new \Symfony\Component\Messenger\Stamp\ReceivedStamp('async')];
            $bus->dispatch(new \Tests\Fixtures\Modules\Alpha\Application\ProbeInbox(), $received);
            self::assertSame(1, $db->fetchOne('SELECT COUNT(*) FROM txn_probe'));
            try {
                $bus->dispatch(new \Tests\Fixtures\Modules\Alpha\Application\ProbeInbox(fail: true), $received);
                self::fail('Expected inbox handler failure after write.');
            } catch (\Symfony\Component\Messenger\Exception\HandlerFailedException $exception) {
                self::assertInstanceOf(\Tests\Fixtures\Modules\Alpha\Domain\ProbeFailure::class, $exception->getPrevious());
                self::assertSame(1, $db->fetchOne('SELECT COUNT(*) FROM txn_probe'));
                self::assertFalse($db->isTransactionActive());
            }
            try {
                $bus->dispatch(new \Tests\Fixtures\Modules\Alpha\Application\ProbeInbox(value: 0), $received);
                self::fail('Expected inbox validation failure.');
            } catch (\Symfony\Component\Messenger\Exception\ValidationFailedException) {
                self::assertSame(1, $db->fetchOne('SELECT COUNT(*) FROM txn_probe'));
            }
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
            $envelope = new \Symfony\Component\Messenger\Envelope(new \Tests\Fixtures\Modules\Alpha\Application\ProbeInbox());
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
            self::assertFalse($container->get(EntityManagerInterface::class)->getMetadataFactory()->hasMetadataFor(Record::class));
        } finally {
            $kernel->shutdown();
        }
    }
}
