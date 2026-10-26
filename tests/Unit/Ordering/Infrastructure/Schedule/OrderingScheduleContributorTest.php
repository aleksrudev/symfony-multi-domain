<?php

declare(strict_types=1);

namespace Tests\Unit\Ordering\Infrastructure\Schedule;

use App\Ordering\Application\Command\ReconcileStock\ReconcileStockCommand;
use App\Ordering\Infrastructure\Schedule\OrderingScheduleContributor;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Stamp\BusNameStamp;
use Symfony\Component\Scheduler\RecurringMessage;

#[CoversClass(OrderingScheduleContributor::class)]
final class OrderingScheduleContributorTest extends TestCase
{
    public function testContributorYieldsRecurringMessageWithCommandBus(): void
    {
        $contributor = new OrderingScheduleContributor(checkInterval: 7, batchSize: 25);
        $messages = iterator_to_array($contributor->getMessages());

        self::assertCount(1, $messages);
        $recurring = $messages[0];
        self::assertInstanceOf(RecurringMessage::class, $recurring);

        $context = new \Symfony\Component\Scheduler\Generator\MessageContext('default', 'reconcile', $recurring->getTrigger(), new \DateTimeImmutable());
        $envelopes = iterator_to_array($recurring->getMessages($context));

        self::assertCount(1, $envelopes);
        $envelope = $envelopes[0];
        self::assertInstanceOf(Envelope::class, $envelope);

        $command = $envelope->getMessage();
        self::assertInstanceOf(ReconcileStockCommand::class, $command);
        self::assertSame(25, $command->batchSize);

        $busStamp = $envelope->last(BusNameStamp::class);
        self::assertNotNull($busStamp);
        self::assertSame('command.bus', $busStamp->getBusName());
    }
}
