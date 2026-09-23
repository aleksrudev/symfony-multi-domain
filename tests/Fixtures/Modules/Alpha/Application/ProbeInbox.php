<?php

declare(strict_types=1);

namespace Tests\Fixtures\Modules\Alpha\Application;

use App\Shared\Application\Bus\Inbox\InboxMessageInterface;
use Symfony\Component\Validator\Constraints as Assert;

final readonly class ProbeInbox implements InboxMessageInterface
{
    public function __construct(#[Assert\Positive] public int $value = 1, public bool $fail = false)
    {
    }
}
