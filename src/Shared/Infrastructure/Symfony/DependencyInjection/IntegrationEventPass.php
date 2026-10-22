<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Symfony\DependencyInjection;

use App\Shared\Infrastructure\Messaging\Attribute\AsIntegrationEvent;
use App\Shared\Infrastructure\Messaging\Attribute\AsIntegrationEventSubscriber;
use App\Shared\Infrastructure\Messaging\IntegrationEventCodecInterface;
use App\Shared\Infrastructure\Messaging\IntegrationEventDefinition;
use App\Shared\Infrastructure\Messaging\IntegrationEventMetadata;
use App\Shared\Infrastructure\Messaging\IntegrationEventRegistry;
use App\Shared\Infrastructure\Messaging\IntegrationEventSubscription;
use Symfony\Component\DependencyInjection\ChildDefinition;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\Compiler\PassConfig;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;

final class IntegrationEventPass implements CompilerPassInterface
{
    private const string CODEC_TAG = 'app.integration_event_codec';
    private const string SUBSCRIBER_TAG = 'app.integration_event_subscriber';

    public static function register(ContainerBuilder $container): void
    {
        $container->registerAttributeForAutoconfiguration(AsIntegrationEvent::class, static function (ChildDefinition $definition, AsIntegrationEvent $attribute): void {
            $definition->addTag(self::CODEC_TAG, ['event' => $attribute->event, 'source' => $attribute->source, 'type' => $attribute->type, 'schemaVersion' => $attribute->schemaVersion]);
        });
        $container->registerAttributeForAutoconfiguration(AsIntegrationEventSubscriber::class, static function (ChildDefinition $definition, AsIntegrationEventSubscriber $attribute): void {
            $definition->addTag(self::SUBSCRIBER_TAG, ['id' => $attribute->id, 'event' => $attribute->event]);
        });
        $container->addCompilerPass(new self(), PassConfig::TYPE_BEFORE_REMOVING);
    }

    public function process(ContainerBuilder $container): void
    {
        if (!$container->hasDefinition(IntegrationEventRegistry::class)) {
            return;
        }
        $definitions = $events = $schemas = [];
        foreach ($container->findTaggedServiceIds(self::CODEC_TAG) as $service => $tags) {
            $class = $container->getDefinition($service)->getClass();
            if (null === $class || !is_subclass_of($class, IntegrationEventCodecInterface::class)) {
                throw new \LogicException(sprintf('Integration event codec "%s" must implement %s.', $service, IntegrationEventCodecInterface::class));
            }
            foreach ($tags as $tag) {
                if (!isset($tag['event'], $tag['source'], $tag['type'], $tag['schemaVersion']) || !is_string($tag['event']) || !class_exists($tag['event']) || !is_string($tag['source']) || !is_string($tag['type']) || !is_int($tag['schemaVersion'])) {
                    throw new \LogicException(sprintf('Invalid integration event definition on "%s".', $service));
                }
                IntegrationEventDefinition::validate($tag['event'], $tag['source'], $tag['type'], $tag['schemaVersion']);
                $key = json_encode([$tag['source'], $tag['type'], $tag['schemaVersion']], JSON_THROW_ON_ERROR);
                if (isset($events[$tag['event']]) || isset($schemas[$key])) {
                    throw new \LogicException(sprintf('Duplicate integration event definition on "%s".', $service));
                }
                $events[$tag['event']] = $schemas[$key] = true;
                $definitions[] = new Definition(IntegrationEventDefinition::class, [$tag['event'], $tag['source'], $tag['type'], $tag['schemaVersion'], new Reference($service)]);
            }
        }
        $subscriptions = $ids = [];
        foreach ($container->findTaggedServiceIds(self::SUBSCRIBER_TAG) as $service => $tags) {
            foreach ($tags as $tag) {
                if (!isset($tag['id'], $tag['event']) || !is_string($tag['id']) || '' === trim($tag['id']) || !is_string($tag['event'])) {
                    throw new \LogicException(sprintf('Invalid integration event subscriber "%s".', $service));
                }
                if (isset($ids[$tag['id']])) {
                    throw new \LogicException(sprintf('Duplicate integration event subscriber "%s".', $tag['id']));
                }
                if (!isset($events[$tag['event']])) {
                    throw new \LogicException(sprintf('Subscriber "%s" uses an unregistered integration event.', $tag['id']));
                }
                $class = $container->getDefinition($service)->getClass();
                $method = null !== $class && method_exists($class, '__invoke') ? new \ReflectionMethod($class, '__invoke') : null;
                $parameters = $method?->getParameters() ?? [];
                if (null === $method || !$method->isPublic() || $method->isStatic() || 2 !== count($parameters)
                    || !$this->hasType($parameters[0]->getType(), $tag['event'])
                    || !$this->hasType($parameters[1]->getType(), IntegrationEventMetadata::class)
                    || !$this->hasType($method->getReturnType(), 'void')) {
                    throw new \LogicException(sprintf('Subscriber "%s" must declare __invoke(%s $event, %s $metadata): void.', $service, $tag['event'], IntegrationEventMetadata::class));
                }
                $ids[$tag['id']] = true;
                $subscriptions[] = new Definition(IntegrationEventSubscription::class, [$tag['id'], $tag['event'], new Reference($service)]);
            }
        }
        $container->getDefinition(IntegrationEventRegistry::class)->setArguments([$definitions, $subscriptions]);
    }

    private function hasType(?\ReflectionType $type, string $name): bool
    {
        return $type instanceof \ReflectionNamedType && !$type->allowsNull() && $name === $type->getName();
    }
}
