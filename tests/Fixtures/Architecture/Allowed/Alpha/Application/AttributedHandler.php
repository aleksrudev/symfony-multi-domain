<?php

declare(strict_types=1);

namespace App\Alpha\Application;

use App\Alpha\Domain\Model;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler(bus: 'command.bus')]
#[AsEventListener(event: Model::class)]
final class AttributedHandler
{
    public function __invoke(Model $message): void
    {
    }
}
