<?php

declare(strict_types=1);

namespace Tests\Unit\Inventory\Application\Command\RequestReservation;

use App\Inventory\Application\Command\RequestReservation\RequestReservationCommand;
use App\Inventory\Application\Command\RequestReservation\RequestReservationHandler;
use App\Inventory\Application\Command\RequestReservation\RequestReservationItemInput;
use App\Inventory\Application\Command\RequestReservation\ReservationQueuePortInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(RequestReservationHandler::class)]
final class RequestReservationHandlerTest extends TestCase
{
    public function testHandlerEnqueuesReservation(): void
    {
        $queuePort = $this->createMock(ReservationQueuePortInterface::class);
        $handler = new RequestReservationHandler($queuePort);

        $orderId = '00000000-0000-4000-8000-000000000001';
        $items = [
            new RequestReservationItemInput('prod-1', 2),
            new RequestReservationItemInput('prod-2', 3),
        ];

        $queuePort
            ->expects(self::once())
            ->method('enqueue')
            ->with($orderId, $items);

        $handler(new RequestReservationCommand($orderId, $items));
    }
}
