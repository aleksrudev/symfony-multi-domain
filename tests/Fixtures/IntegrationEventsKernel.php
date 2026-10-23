<?php

declare(strict_types=1);

namespace Tests\Fixtures;

use App\Shared\Infrastructure\Messaging\IntegrationEventDispatcher;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Tests\Fixtures\Modules\Alpha\Application\PublishProbeEventHandler;
use Tests\Fixtures\Modules\Alpha\Infrastructure\Messaging\PublishProbeEventListener;
use Tests\Fixtures\Modules\Beta\Application\Message\FailProbeHandler;
use Tests\Fixtures\Modules\Beta\Application\Message\RecordProbeHandler;
use Tests\Fixtures\Modules\Beta\Infrastructure\Integration\Alpha\EnqueueFailingProbeListener;
use Tests\Fixtures\Modules\Beta\Infrastructure\Integration\Alpha\EnqueueRecordingProbeListener;

class IntegrationEventsKernel extends ModuleKernel
{
    protected function configureContainer(ContainerConfigurator $container): void
    {
        parent::configureContainer($container);
        $container->extension('framework', ['messenger' => ['transports' => [
            'async' => ['dsn' => 'doctrine://default?queue_name=async', 'options' => ['auto_setup' => false], 'retry_strategy' => ['max_retries' => 3, 'delay' => 0, 'jitter' => 0]],
            'outbox' => ['retry_strategy' => ['max_retries' => 3, 'delay' => 0, 'jitter' => 0]],
            'failed' => ['dsn' => 'doctrine://default?queue_name=failed', 'options' => ['auto_setup' => false]],
        ]]]);
        $services = $container->services()->defaults()->autowire()->autoconfigure();
        $services->set(PublishProbeEventListener::class);
        $services->set(PublishProbeEventHandler::class);
        $services->set(EnqueueRecordingProbeListener::class);
        $services->set(EnqueueFailingProbeListener::class);
        $services->set(RecordProbeHandler::class)->public();
        $services->set(FailProbeHandler::class)->public();
        $services->alias('test.integration_event_dispatcher', IntegrationEventDispatcher::class)->public();
    }
}
