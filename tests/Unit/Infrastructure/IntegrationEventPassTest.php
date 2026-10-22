<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure;

use App\Shared\Infrastructure\Messaging\IntegrationEventRegistry;
use App\Shared\Infrastructure\Symfony\DependencyInjection\IntegrationEventPass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Tests\Fixtures\Modules\Alpha\Infrastructure\Messaging\ProbeEventCodec;
use Tests\Fixtures\Modules\Alpha\Presentation\Contracts\Dto\ProbeItem;
use Tests\Fixtures\Modules\Alpha\Presentation\Contracts\Event\ProbePublishedEvent;
use Tests\Fixtures\Modules\Beta\Infrastructure\Integration\Alpha\RecordingSubscriber;

final class IntegrationEventPassTest extends TestCase
{
    public function testAttributesWireTheCodecAndSubscriberWithoutYamlTags(): void
    {
        $container = $this->container();
        $container->compile();
        $registry = $container->get(IntegrationEventRegistry::class);
        self::assertInstanceOf(IntegrationEventRegistry::class, $registry);

        $event = new ProbePublishedEvent(7, [new ProbeItem('item-1', 3)]);
        self::assertSame('alpha.published', $registry->definitionForEvent($event)->type);
        self::assertSame('beta.record', $registry->subscribersForEvent($event)[0]->id);
    }

    public function testDuplicateSchemaIsRejectedDuringCompilation(): void
    {
        $container = $this->container();
        $container->register('duplicate.codec', ProbeEventCodec::class)->setAutoconfigured(true);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('Duplicate');
        $container->compile();
    }

    public function testDuplicateSubscriberIdIsRejectedDuringCompilation(): void
    {
        $container = $this->container();
        $container->register('duplicate.subscriber', RecordingSubscriber::class)->setAutoconfigured(true);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('Duplicate');
        $container->compile();
    }

    public function testSubscriberWithNoSchemaIsRejectedDuringCompilation(): void
    {
        $container = $this->container();
        $container->removeDefinition(ProbeEventCodec::class);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('unregistered');
        $container->compile();
    }

    private function container(): ContainerBuilder
    {
        $container = new ContainerBuilder();
        $container->register(IntegrationEventRegistry::class)->setPublic(true);
        $container->register(ProbeEventCodec::class)->setAutoconfigured(true);
        $container->register(RecordingSubscriber::class)->setAutoconfigured(true);
        IntegrationEventPass::register($container);

        return $container;
    }
}
