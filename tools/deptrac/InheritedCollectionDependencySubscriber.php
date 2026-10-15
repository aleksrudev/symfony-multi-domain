<?php

declare(strict_types=1);

namespace Tools\Deptrac;

use Deptrac\Deptrac\Contract\Analyser\ProcessEvent;
use Deptrac\Deptrac\Contract\Result\Allowed;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

final class InheritedCollectionDependencySubscriber implements EventSubscriberInterface
{
    public function __construct(private readonly string $baseFile)
    {
    }

    public static function getSubscribedEvents(): array
    {
        return [ProcessEvent::class => ['onDependency', 100]];
    }

    public function onDependency(ProcessEvent $event): void
    {
        if (!str_ends_with($event->dependerLayer, '.Collection')
            || !array_key_exists('DoctrineCollections', $event->dependentLayers)
            || $event->dependency->getContext()->fileOccurrence->filepath !== $this->baseFile
        ) {
            return;
        }

        $event->getResult()->addRule(new Allowed($event->dependency, $event->dependerLayer, 'DoctrineCollections'));
        $event->stopPropagation();
    }
}
