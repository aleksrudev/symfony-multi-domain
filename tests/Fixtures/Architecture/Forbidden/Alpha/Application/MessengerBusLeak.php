<?php

declare(strict_types=1);

namespace App\Alpha\Application;

use Symfony\Component\Messenger\MessageBusInterface;

final readonly class MessengerBusLeak
{
    public function __construct(private MessageBusInterface $bus)
    {
    }
}
