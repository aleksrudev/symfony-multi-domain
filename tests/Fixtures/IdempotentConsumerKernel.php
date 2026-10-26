<?php

declare(strict_types=1);

namespace Tests\Fixtures;

use App\Shared\Infrastructure\Messaging\IdempotentConsumer\Consumer\IdempotentConsumerInterface;
use Doctrine\Bundle\DoctrineBundle\DoctrineBundle;
use Psr\Clock\ClockInterface;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Kernel;

final class IdempotentConsumerKernel extends Kernel
{
    use MicroKernelTrait;

    public function registerBundles(): iterable
    {
        yield new FrameworkBundle();
        yield new DoctrineBundle();
    }

    public function getProjectDir(): string
    {
        return dirname(__DIR__, 2);
    }

    public function getCacheDir(): string
    {
        return $this->getProjectDir().'/var/cache/fixtures/IdempotentConsumerKernel/'.$this->environment;
    }

    protected function configureContainer(ContainerConfigurator $container): void
    {
        $url = getenv('CONSUMER_TEST_DATABASE_URL');
        if (!is_string($url) || '' === $url) {
            throw new \LogicException('CONSUMER_TEST_DATABASE_URL is required.');
        }
        $services = $container->services()->defaults()->autowire()->autoconfigure();
        $services->load('App\\Shared\\Infrastructure\\Messaging\\IdempotentConsumer\\Consumer\\', $this->getProjectDir().'/src/Shared/Infrastructure/Messaging/IdempotentConsumer/Consumer/');
        $services->alias('test.idempotent_consumer', IdempotentConsumerInterface::class)->public();
        $services->set(MockClock::class)->args(['2026-10-26T12:00:00.123456+03:00']);
        $services->alias(ClockInterface::class, MockClock::class);
        $container->extension('framework', ['secret' => 'consumer-test', 'test' => true, 'http_method_override' => false]);
        $container->import($this->getProjectDir().'/config/packages/doctrine.yaml');
        $container->extension('doctrine', [
            'dbal' => ['connections' => ['default' => ['url' => $url, 'dbname_suffix' => '']]],
            'orm' => ['entity_managers' => ['default' => ['mappings' => [
                'ConsumerProbe' => ['type' => 'attribute', 'dir' => __DIR__.'/Messaging/Consumer/Entity', 'prefix' => 'Tests\\Fixtures\\Messaging\\Consumer\\Entity', 'is_bundle' => false],
            ]]]],
        ]);
    }
}
