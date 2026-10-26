<?php

declare(strict_types=1);

namespace Tests\Integration;

use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Process\Process;
use Symfony\Component\Yaml\Yaml;
use Tests\Fixtures\Messaging\Integration\MessagingTestCase;

final class WorkerHealthCheckTest extends MessagingTestCase
{
    #[DataProvider('workers')]
    public function testComposeHealthCheckRecognizesTheRunningWorker(string $service, string $idleSleep): void
    {
        $project = dirname(__DIR__, 2);
        $config = Yaml::parseFile($project.'/compose.yaml');
        $definition = $config['services'][$service]['healthcheck']['test'];
        // Compose resolves escaped dollar signs before executing the healthcheck.
        $definition = array_map(static fn (string $argument): string => str_replace('$$', '$', $argument), $definition);
        $health = new Process('CMD-SHELL' === $definition[0] ? ['/bin/sh', '-c', $definition[1]] : array_slice($definition, 1));
        self::assertSame(1, $health->run(), 'No worker is running yet.');
        $configuredCommand = $config['services'][$service]['command'];
        $worker = new Process([PHP_BINARY, $project.'/bin/console', ...array_slice($configuredCommand, 2), '--env=test', '--time-limit=2', '--sleep='.$idleSleep], $project, timeout: 10);
        $worker->start();
        try {
            self::assertTrue($worker->isRunning(), $worker->getErrorOutput());
            $health->run();
            self::assertSame(0, $health->getExitCode(), $health->getErrorOutput());
            self::assertSame(0, $worker->wait(), $worker->getErrorOutput());
            self::assertSame(1, $health->run(), 'Stopped worker must no longer be healthy.');
        } finally {
            $worker->stop();
        }
    }

    /** @return iterable<string, array{string, string}> */
    public static function workers(): iterable
    {
        yield 'outbox' => ['outbox-worker', '10'];
        yield 'async' => ['worker', '0.01'];
        yield 'scheduler' => ['scheduler', '0.01'];
    }
}
