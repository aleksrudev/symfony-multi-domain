<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Messaging\Outbox\Console;

use Doctrine\DBAL\Connection;
use Psr\Clock\ClockInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'app:outbox:retry', description: 'Retry one failed Outbox snapshot')]
final class RetryOutboxCommand extends Command
{
    public function __construct(private readonly Connection $connection, private readonly ClockInterface $clock)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('id', InputArgument::REQUIRED, 'Failed Outbox record ID');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $id = $input->getArgument('id');
        if (!is_string($id) || !preg_match('/^[1-9][0-9]*$/D', $id) || false === filter_var($id, FILTER_VALIDATE_INT)) {
            $output->writeln('<error>id must be a positive integer.</error>');

            return self::INVALID;
        }
        $count = $this->connection->executeStatement('UPDATE shared.outbox_messages SET attempts = 0, failed_at = NULL, last_error = NULL, available_at = ? WHERE id = ? AND failed_at IS NOT NULL', [$this->clock->now()->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s.u'), $id]);
        if (1 !== $count) {
            $output->writeln('<error>Failed Outbox record was not found.</error>');

            return self::FAILURE;
        }
        $output->writeln('Outbox record scheduled for retry.');

        return self::SUCCESS;
    }
}
