<?php

declare(strict_types=1);

namespace App\Alpha\Domain;

use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler(bus: 'command.bus')]
final class AttributeLeak
{
}
