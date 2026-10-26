<?php

declare(strict_types=1);

namespace App\Alpha\Infrastructure\Integration\Beta;

use App\Beta\Presentation\Contracts\Event\StockChangedEvent;

final readonly class AllowedEventAccess
{
    public function __construct(private StockChangedEvent $value)
    {
    }
}
