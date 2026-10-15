<?php

declare(strict_types=1);

namespace App\Shared\Domain\Event;

interface EventBusInterface
{
    public function dispatch(EventInterface $event): void;
}
