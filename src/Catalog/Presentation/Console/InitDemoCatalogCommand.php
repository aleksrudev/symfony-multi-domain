<?php

declare(strict_types=1);

namespace App\Catalog\Presentation\Console;

use App\Catalog\Application\Command\InitDemo\InitDemoCatalogCommand as ApplicationInitDemoCatalogCommand;
use App\Shared\Application\Bus\Command\CommandBusInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'catalog:init-demo',
    description: 'Initializes idempotent demo catalog products (dev/test only).',
)]
final class InitDemoCatalogCommand extends Command
{
    public function __construct(
        private readonly CommandBusInterface $commandBus,
        private readonly string $environment,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if ('prod' === $this->environment) {
            $output->writeln('<error>Demo seed commands cannot be executed in production.</error>');

            return Command::FAILURE;
        }

        $this->commandBus->dispatch(new ApplicationInitDemoCatalogCommand());

        $output->writeln('<info>Demo catalog initialized successfully.</info>');

        return Command::SUCCESS;
    }
}

