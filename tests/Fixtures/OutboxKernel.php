<?php

declare(strict_types=1);

namespace Tests\Fixtures;

use App\Shared\Infrastructure\Messaging\Outbox\Writer\OutboxWriterInterface;
use Doctrine\Bundle\DoctrineBundle\DoctrineBundle;
use Psr\Clock\ClockInterface;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Kernel;

final class OutboxKernel extends Kernel
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
        return $this->getProjectDir().'/var/cache/fixtures/OutboxKernel/'.$this->environment;
    }

    protected function configureContainer(ContainerConfigurator $container): void
    {
        $url = getenv('OUTBOX_TEST_DATABASE_URL');
        if (!is_string($url) || '' === $url) {
            throw new \LogicException('OUTBOX_TEST_DATABASE_URL is required.');
        }
        $services = $container->services()->defaults()->autowire()->autoconfigure();
        $services->load('App\\Shared\\Infrastructure\\Messaging\\Outbox\\', $this->getProjectDir().'/src/Shared/Infrastructure/Messaging/Outbox/');
        $services->load('Tests\\Fixtures\\Messaging\\', __DIR__.'/Messaging/*Publisher.php');
        $services->set(MockClock::class)->args(['2026-10-26T09:00:00Z']);
        $services->alias(ClockInterface::class, MockClock::class);
        $services->alias(\Symfony\Component\Clock\ClockInterface::class, MockClock::class);
        $services->alias('test.outbox_writer', OutboxWriterInterface::class)->public();
        $container->extension('framework', ['secret' => 'outbox-test', 'test' => true, 'http_method_override' => false]);
        $container->import($this->getProjectDir().'/config/packages/doctrine.yaml');
        $container->extension('doctrine', [
            'dbal' => ['connections' => ['default' => ['url' => $url, 'dbname_suffix' => '']]],
            'orm' => ['entity_managers' => ['default' => ['mappings' => [
                'OutboxProbe' => ['type' => 'attribute', 'dir' => __DIR__.'/Messaging/Entity', 'prefix' => 'Tests\\Fixtures\\Messaging\\Entity', 'is_bundle' => false],
            ]]]],
        ]);
    }
}
