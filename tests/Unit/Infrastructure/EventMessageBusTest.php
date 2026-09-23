<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure;

use App\Shared\Domain\Event\EventInterface;
use App\Shared\Infrastructure\Bus\Symfony\EventMessageBus;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;

final class EventMessageBusTest extends TestCase
{
    public function testDispatchIsSynchronousAndPreservesTheEvent(): void
    {
        $dispatcher = new EventDispatcher();
        $event = new class implements EventInterface {};
        $received = null;
        $dispatcher->addListener($event::class, static function (EventInterface $event) use (&$received): void {
            $received = $event;
        });
        new EventMessageBus($dispatcher)->dispatch($event);
        self::assertSame($event, $received);
    }

    public function testListenerFailurePropagatesToTheCaller(): void
    {
        $dispatcher = new EventDispatcher();
        $event = new class implements EventInterface {};
        $failure = new \RuntimeException('listener failure');
        $dispatcher->addListener($event::class, static function () use ($failure): never {
            throw $failure;
        });
        $this->expectExceptionObject($failure);
        new EventMessageBus($dispatcher)->dispatch($event);
    }
}
