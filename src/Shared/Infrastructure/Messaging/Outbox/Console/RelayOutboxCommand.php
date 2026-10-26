<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Messaging\Outbox\Console;

use App\Shared\Infrastructure\Messaging\Outbox\Relay\OutboxRelay;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'app:outbox:relay', description: 'Relay committed Outbox snapshots')]
final class RelayOutboxCommand extends Command
{
    private bool $stopRequested = false;

    public function __construct(private readonly OutboxRelay $relay, private readonly ClockInterface $clock)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Maximum attempted messages', '100')
            ->addOption('time-limit', null, InputOption::VALUE_REQUIRED, 'Maximum runtime in seconds', '3600')
            ->addOption('sleep', null, InputOption::VALUE_REQUIRED, 'Idle sleep in milliseconds', '1000');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $options = [];
        foreach (['limit', 'time-limit', 'sleep'] as $name) {
            $value = $input->getOption($name);
            if (!is_string($value) || !preg_match('/^[1-9][0-9]*$/D', $value) || false === ($number = filter_var($value, FILTER_VALIDATE_INT))) {
                $output->writeln('<error>--'.$name.' must be a positive integer.</error>');

                return self::INVALID;
            }
            $options[$name] = $number;
        }
        $this->stopRequested = false;
        $deadline = (float) $this->clock->now()->format('U.u') + $options['time-limit'];
        $attempted = 0;
        while (!$this->shouldStop() && $attempted < $options['limit'] && (float) $this->clock->now()->format('U.u') < $deadline) {
            if ($this->relay->relayOne()) {
                ++$attempted;
            } else {
                $remaining = max(0, $deadline - (float) $this->clock->now()->format('U.u'));
                $this->clock->sleep(min($options['sleep'] / 1000, $remaining));
            }
        }
        $output->writeln(sprintf('Outbox attempts completed: %d.', $attempted));

        return self::SUCCESS;
    }

    private function shouldStop(): bool
    {
        return $this->stopRequested;
    }

    /** @return list<int> */
    public function getSubscribedSignals(): array
    {
        return \extension_loaded('pcntl') ? [\SIGTERM, \SIGINT] : [];
    }

    public function handleSignal(int $signal, int|false $previousExitCode = 0): int|false
    {
        $this->stopRequested = true;

        return false;
    }
}
