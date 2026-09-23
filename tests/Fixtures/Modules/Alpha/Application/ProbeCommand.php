<?php

declare(strict_types=1);

namespace Tests\Fixtures\Modules\Alpha\Application;

use App\Shared\Application\Bus\Command\CommandInterface;
use Symfony\Component\Validator\Constraints as Assert;

final readonly class ProbeCommand implements CommandInterface
{
    public function __construct(#[Assert\Positive] public int $value = 1, public bool $fail = false)
    {
    }
}
