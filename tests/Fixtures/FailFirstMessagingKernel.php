<?php

declare(strict_types=1);

namespace Tests\Fixtures;

use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\Compiler\PassConfig;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Tests\Fixtures\Modules\Beta\Infrastructure\Integration\Alpha\FailProbeConsumer;

final class FailFirstMessagingKernel extends TransactionalMessagingKernel implements CompilerPassInterface
{
    protected function build(ContainerBuilder $container): void
    {
        parent::build($container);
        // After attribute autoconfiguration, before Messenger builds its locator.
        $container->addCompilerPass($this, PassConfig::TYPE_BEFORE_OPTIMIZATION, 1);
    }

    public function process(ContainerBuilder $container): void
    {
        $definition = $container->findDefinition(FailProbeConsumer::class);
        $tags = $definition->getTag('messenger.message_handler');
        $definition->clearTag('messenger.message_handler');
        foreach ($tags as $tag) {
            $definition->addTag('messenger.message_handler', array_replace($tag, ['priority' => 20]));
        }
    }
}
