<?php

declare(strict_types=1);

namespace Tests\Fixtures\Messaging\Integration;

final readonly class ProcessBarrier
{
    public function __construct(public string $path)
    {
    }

    public function signal(string $phase, string $value = 'ready'): void
    {
        $target = $this->path.'.'.$phase;
        file_put_contents($target.'.tmp', $value);
        rename($target.'.tmp', $target);
    }

    public function wait(string $phase, float $timeout = 8): string
    {
        $target = $this->path.'.'.$phase;
        $deadline = microtime(true) + $timeout;
        do {
            clearstatcache(true, $target);
            if (is_file($target)) {
                return (string) file_get_contents($target);
            }
            usleep(10000);
        } while (microtime(true) < $deadline);
        throw new \RuntimeException('Process barrier timed out: '.$phase);
    }

    public function clear(): void
    {
        foreach (glob($this->path.'.*') ?: [] as $file) {
            unlink($file);
        }
    }
}
