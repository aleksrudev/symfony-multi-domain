<?php

declare(strict_types=1);

namespace Tests\Fixtures;

use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

class TransactionalMessagingKernel extends ModuleKernel
{
    protected function moduleDirectories(): array
    {
        return parent::moduleDirectories() + ['Beta' => __DIR__.'/Modules/Beta'];
    }

    protected function configureContainer(ContainerConfigurator $container): void
    {
        parent::configureContainer($container);
        // Real commits must be visible to independent PostgreSQL observers/workers.
        $container->extension('dama_doctrine_test', ['enable_static_connection' => false]);
        $url = getenv('MESSAGING_TEST_DATABASE_URL');
        if (!is_string($url) || '' === $url) {
            throw new \LogicException('MESSAGING_TEST_DATABASE_URL is required.');
        }
        $container->extension('doctrine', ['dbal' => ['connections' => ['default' => ['url' => $url, 'dbname_suffix' => '']]]]);
        $container->extension('framework', ['messenger' => ['transports' => [
            'async' => ['dsn' => 'doctrine://default?queue_name=async', 'options' => ['auto_setup' => false], 'retry_strategy' => ['delay' => 0, 'max_delay' => 0]],
            'failed' => ['dsn' => 'doctrine://default?queue_name=failed', 'options' => ['auto_setup' => false]],
        ]]]);
    }
}
