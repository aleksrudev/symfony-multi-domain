<?php

declare(strict_types=1);

namespace App\Ordering\Infrastructure\Schedule;

use App\Ordering\Application\Command\ReconcileStock\ReconcileStockCommand;
use App\Shared\Infrastructure\Schedule\ScheduleContributorInterface;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Stamp\BusNameStamp;
use Symfony\Component\Scheduler\RecurringMessage;

final readonly class OrderingScheduleContributor implements ScheduleContributorInterface
{
    public function __construct(
        private int $checkInterval = 5,
        private int $batchSize = 50,
    ) {
    }

    public function getMessages(): iterable
    {
        yield RecurringMessage::every(
            $this->checkInterval.' seconds',
            new Envelope(new ReconcileStockCommand($this->batchSize), [new BusNameStamp('command.bus')]),
        );
    }
}
