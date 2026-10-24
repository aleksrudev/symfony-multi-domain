<?php

declare(strict_types=1);

namespace Tests\Unit\Ordering\Infrastructure\Messaging\Outbox\RequestReservation;

use App\Ordering\Infrastructure\Messaging\Outbox\RequestReservation\InventoryReservationGatewayInterface;
use App\Ordering\Infrastructure\Messaging\Outbox\RequestReservation\RequestReservationOutboxHandler;
use App\Ordering\Infrastructure\Messaging\Outbox\RequestReservation\RequestReservationOutboxItem;
use App\Ordering\Infrastructure\Messaging\Outbox\RequestReservation\RequestReservationOutboxMessage;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(RequestReservationOutboxHandler::class)]
final class RequestReservationOutboxHandlerTest extends TestCase
{
    public function testHandlerCallsGateway(): void
    {
        $gateway = $this->createMock(InventoryReservationGatewayInterface::class);
        $handler = new RequestReservationOutboxHandler($gateway);

        $orderId = '00000000-0000-4000-8000-000000000001';
        $items = [
            new RequestReservationOutboxItem('prod-1', 2),
        ];

        $gateway
            ->expects(self::once())
            ->method('requestReservation')
            ->with($orderId, $items);

        $handler(new RequestReservationOutboxMessage($orderId, $items));
    }
}
