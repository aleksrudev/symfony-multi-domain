<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Schedule;

use Symfony\Component\Scheduler\RecurringMessage;

interface ScheduleContributorInterface
{
    /** @return iterable<RecurringMessage> */
    public function getMessages(): iterable;
}
