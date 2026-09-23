<?php

declare(strict_types=1);

namespace Tests\Fixtures\Modules\Alpha\Infrastructure;

use App\Shared\Infrastructure\Schedule\ScheduleContributorInterface;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Message\RedispatchMessage;
use Symfony\Component\Messenger\Stamp\BusNameStamp;
use Symfony\Component\Scheduler\RecurringMessage;
use Tests\Fixtures\Modules\Alpha\Application\ProbeInbox;

final class ProbeSchedule implements ScheduleContributorInterface
{
    public function getMessages(): iterable
    {
        yield RecurringMessage::every('1 minute', new RedispatchMessage(new Envelope(new ProbeInbox(), [new BusNameStamp('inbox.bus')]), 'async'));
    }
}
