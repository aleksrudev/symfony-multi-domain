<?php

declare(strict_types=1);

namespace Tests\Fixtures;

final class NoSubscribersMessagingKernel extends TransactionalMessagingKernel
{
    protected function moduleDirectories(): array
    {
        return ['Alpha' => __DIR__.'/Modules/Alpha'];
    }
}
