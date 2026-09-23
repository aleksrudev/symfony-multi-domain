<?php

declare(strict_types=1);

namespace Tests\Fixtures\Modules\Alpha\Application;

interface ProbeStoreInterface
{
    public function append(int $value): void;
}
