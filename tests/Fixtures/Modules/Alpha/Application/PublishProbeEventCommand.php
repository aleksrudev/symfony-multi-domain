<?php

declare(strict_types=1);

namespace Tests\Fixtures\Modules\Alpha\Application;

use App\Shared\Application\Bus\Command\CommandInterface;

final readonly class PublishProbeEventCommand implements CommandInterface
{
    public function __construct(public int $value, public bool $fail = false)
    {
    }
}
