<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Bus\Symfony;

use App\Shared\Domain\Event\EventBusInterface;
use App\Shared\Domain\Event\EventInterface;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

#[AsAlias(EventBusInterface::class)]
class EventMessageBus implements EventBusInterface
{
    public function __construct(
        private EventDispatcherInterface $dispatcher,
    ) {
    }

    public function dispatch(EventInterface $event): void
    {
        $this->dispatcher->dispatch($event);
    }
}
