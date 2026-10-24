<?php

declare(strict_types=1);

namespace Tests\Unit\Inventory\Application\Command\RequestRelease;

use App\Inventory\Application\Command\RequestRelease\ReleaseQueuePortInterface;
use App\Inventory\Application\Command\RequestRelease\RequestReleaseCommand;
use App\Inventory\Application\Command\RequestRelease\RequestReleaseHandler;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(RequestReleaseHandler::class)]
final class RequestReleaseHandlerTest extends TestCase
{
    public function testHandlerEnqueuesRelease(): void
    {
        $queuePort = $this->createMock(ReleaseQueuePortInterface::class);
        $handler = new RequestReleaseHandler($queuePort);

        $orderId = '00000000-0000-4000-8000-000000000002';

        $queuePort
            ->expects(self::once())
            ->method('enqueue')
            ->with($orderId);

        $handler(new RequestReleaseCommand($orderId));
    }
}
