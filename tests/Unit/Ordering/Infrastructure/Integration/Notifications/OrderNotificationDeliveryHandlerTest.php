<?php

declare(strict_types=1);

namespace Tests\Unit\Ordering\Infrastructure\Integration\Notifications;

use App\Notifications\Presentation\Contracts\Dto\EnqueueNotificationInput;
use App\Notifications\Presentation\Contracts\NotificationsContractInterface;
use App\Ordering\Infrastructure\Integration\Notifications\OrderNotificationDeliveryHandler;
use App\Ordering\Infrastructure\Messaging\Outbox\OrderNotification\OrderNotificationMessage;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(OrderNotificationDeliveryHandler::class)]
final class OrderNotificationDeliveryHandlerTest extends TestCase
{
    public function testDeliveryHandlerEnqueuesNotificationInNotificationsContract(): void
    {
        $message = new OrderNotificationMessage(
            orderId: '00000000-0000-4000-8000-000000000001',
            type: 'order_confirmed',
            buyerId: 'buyer-1',
            payload: ['totalAmount' => 20000],
        );

        $contract = $this->createMock(NotificationsContractInterface::class);
        $contract->expects(self::once())
            ->method('enqueueNotification')
            ->with(self::callback(static fn (EnqueueNotificationInput $input): bool => '00000000-0000-4000-8000-000000000001' === $input->orderId
                && 'order_confirmed' === $input->type
                && 'buyer-1' === $input->buyerId
                && 20000 === ($input->payload['totalAmount'] ?? null)));

        $handler = new OrderNotificationDeliveryHandler($contract);
        $handler($message);
    }
}

