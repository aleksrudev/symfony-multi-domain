<?php

declare(strict_types=1);

namespace Tests\Fixtures\Modules\Alpha\Application;

use Tests\Fixtures\Modules\Alpha\Domain\ProbeFailure;

final class ProbeHandler
{
    public int $calls = 0;

    public function __construct(private readonly ProbeStoreInterface $store)
    {
    }

    public function command(ProbeCommand $command): void
    {
        $this->write($command->value, $command->fail);
    }

    public function query(ProbeQuery $query): int
    {
        return $this->calls;
    }

    public function inbox(ProbeInbox $message): void
    {
        $this->write($message->value, $message->fail);
    }

    private function write(int $value, bool $fail): void
    {
        $this->store->append($value);
        if ($fail) {
            throw new ProbeFailure();
        }
        ++$this->calls;
    }
}
