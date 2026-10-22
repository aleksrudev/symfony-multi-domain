<?php

declare(strict_types=1);

namespace App\Alpha\Presentation\Contracts\Event;

use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

#[AsEventListener(event: self::class)]
final readonly class AttributeLeakEvent
{
}
