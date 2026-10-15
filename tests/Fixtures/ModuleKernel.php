<?php

declare(strict_types=1);

namespace Tests\Fixtures;

use App\Kernel;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

class ModuleKernel extends Kernel
{
    public function getProjectDir(): string
    {
        return dirname(__DIR__, 2);
    }

    public function getCacheDir(): string
    {
        return $this->getProjectDir().'/var/cache/fixtures/'.str_replace('\\', '_', static::class).'/'.$this->environment;
    }

    protected function moduleDirectories(): array
    {
        return ['Alpha' => __DIR__.'/Modules/Alpha'];
    }

    protected function configureContainer(ContainerConfigurator $container): void
    {
        parent::configureContainer($container);
        $container->extension('doctrine', ['dbal' => ['connections' => ['default' => ['url' => 'sqlite:///:memory:']]]]);
        $container->extension('dama_doctrine_test', ['enable_static_connection' => false]);
        $container->extension('framework', ['messenger' => ['transports' => ['async' => ['dsn' => 'in-memory://', 'options' => []], 'failed' => ['dsn' => 'in-memory://']]]]);
    }
}
