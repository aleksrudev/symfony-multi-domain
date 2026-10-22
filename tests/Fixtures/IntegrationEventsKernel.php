<?php

declare(strict_types=1);

namespace Tests\Fixtures;

use App\Shared\Infrastructure\Messaging\IntegrationEventPublisher;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Tests\Fixtures\Modules\Alpha\Application\PublishProbeEventHandler;
use Tests\Fixtures\Modules\Alpha\Infrastructure\Messaging\ProbeEventCodec;
use Tests\Fixtures\Modules\Alpha\Infrastructure\Messaging\PublishProbeEventListener;
use Tests\Fixtures\Modules\Beta\Infrastructure\Integration\Alpha\FailingSubscriber;
use Tests\Fixtures\Modules\Beta\Infrastructure\Integration\Alpha\RecordingSubscriber;

class IntegrationEventsKernel extends ModuleKernel
{
    protected function configureContainer(ContainerConfigurator $container): void
    {
        parent::configureContainer($container);
        $services = $container->services()->defaults()->autowire()->autoconfigure();
        $services->set(ProbeEventCodec::class);
        $services->set(PublishProbeEventListener::class);
        $services->set(PublishProbeEventHandler::class);
        $services->set(RecordingSubscriber::class)->public();
        $services->set(FailingSubscriber::class)->public();
        $services->alias('test.integration_event_publisher', IntegrationEventPublisher::class)->public();
    }
}
