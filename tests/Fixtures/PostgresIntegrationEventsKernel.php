<?php

declare(strict_types=1);

namespace Tests\Fixtures;

use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

final class PostgresIntegrationEventsKernel extends IntegrationEventsKernel
{
    protected function configureContainer(ContainerConfigurator $container): void
    {
        parent::configureContainer($container);
        $url = getenv('OUTBOX_TEST_DATABASE_URL');
        if (!is_string($url) || '' === $url) {
            throw new \LogicException('OUTBOX_TEST_DATABASE_URL is required.');
        }
        $container->extension('doctrine', ['dbal' => ['connections' => ['default' => ['url' => $url, 'dbname_suffix' => '']]]]);
    }
}
