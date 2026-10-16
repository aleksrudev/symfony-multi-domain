<?php

declare(strict_types=1);

namespace Tools\Deptrac;

use Deptrac\Deptrac\Contract\Analyser\ProcessEvent;
use Deptrac\Deptrac\Contract\Result\Allowed;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

final class InheritedUidDependencySubscriber implements EventSubscriberInterface
{
    /** @param list<string> $baseFiles */
    public function __construct(private readonly array $baseFiles)
    {
    }

    public static function getSubscribedEvents(): array
    {
        return [ProcessEvent::class => ['onDependency', 100]];
    }

    public function onDependency(ProcessEvent $event): void
    {
        if (!str_ends_with($event->dependerLayer, '.Domain')
            || !array_key_exists('SymfonyUid', $event->dependentLayers)
            || !in_array($event->dependency->getContext()->fileOccurrence->filepath, $this->baseFiles, true)
        ) {
            return;
        }

        $event->getResult()->addRule(new Allowed($event->dependency, $event->dependerLayer, 'SymfonyUid'));
        $event->stopPropagation();
    }
}
