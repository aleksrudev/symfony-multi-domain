<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Schedule;

use Symfony\Component\Scheduler\Attribute\AsSchedule;
use Symfony\Component\Scheduler\Schedule as SymfonySchedule;
use Symfony\Component\Scheduler\ScheduleProviderInterface;
use Symfony\Contracts\Cache\CacheInterface;

#[AsSchedule('default')]
final readonly class Schedule implements ScheduleProviderInterface
{
    /** @param iterable<ScheduleContributorInterface> $contributors */
    public function __construct(private CacheInterface $cache, private iterable $contributors = [])
    {
    }

    public function getSchedule(): SymfonySchedule
    {
        $schedule = new SymfonySchedule()->stateful($this->cache)->processOnlyLastMissedRun(true);
        foreach ($this->contributors as $contributor) {
            foreach ($contributor->getMessages() as $message) {
                $schedule->add($message);
            }
        }

        return $schedule;
    }
}
