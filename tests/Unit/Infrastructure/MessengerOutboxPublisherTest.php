<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure;

use App\Shared\Infrastructure\Messaging\Messenger\MessengerOutboxPublisher;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Messenger\Handler\HandlersLocator;
use Symfony\Component\Messenger\MessageBus;
use Symfony\Component\Messenger\Middleware\HandleMessageMiddleware;

final class MessengerOutboxPublisherTest extends TestCase
{
    public function testPlainObjectIsDeliveredThroughTheInjectedBus(): void
    {
        $received = [];
        $bus = new MessageBus([new HandleMessageMiddleware(new HandlersLocator([
            \stdClass::class => [static function (\stdClass $message) use (&$received): void { $received[] = $message->value; }],
        ]))]);
        new MessengerOutboxPublisher($bus)->publish((object) ['value' => 'plain object']);
        self::assertSame(['plain object'], $received);
    }
}
