<?php

declare(strict_types=1);

namespace Tests\Functional\Catalog;

use App\Kernel;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class InitDemoCatalogCommandTest extends TestCase
{
    public function testInitializesDemoProductsIdempotently(): void
    {
        $kernel = new Kernel('test', true);
        $kernel->boot();

        $application = new Application($kernel);
        $command = $application->find('catalog:init-demo');
        $tester = new CommandTester($command);

        $statusCode1 = $tester->execute([]);
        self::assertSame(Command::SUCCESS, $statusCode1);
        self::assertStringContainsString('Demo catalog initialized', $tester->getDisplay());

        $statusCode2 = $tester->execute([]);
        self::assertSame(Command::SUCCESS, $statusCode2);

        $kernel->shutdown();
    }

    public function testRefusesToExecuteInProdEnvironment(): void
    {
        $kernel = new Kernel('prod', false);
        $kernel->boot();

        $application = new Application($kernel);
        $command = $application->find('catalog:init-demo');
        $tester = new CommandTester($command);

        $statusCode = $tester->execute([]);
        self::assertSame(Command::FAILURE, $statusCode);
        self::assertStringContainsString('cannot be executed in production', $tester->getDisplay());

        $kernel->shutdown();
    }
}

