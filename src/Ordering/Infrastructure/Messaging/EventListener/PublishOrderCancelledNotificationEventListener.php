<?php

declare(strict_types=1);

namespace App\Ordering\Infrastructure\Messaging\EventListener;

use App\Ordering\Domain\Event\OrderCancellationFinalizedEvent;
use App\Ordering\Infrastructure\Messaging\Outbox\OrderNotification\OrderNotificationMessage;
use App\Shared\Infrastructure\Messaging\Outbox\Writer\OutboxWriterInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

#[AsEventListener(event: OrderCancellationFinalizedEvent::class)]
final readonly class PublishOrderCancelledNotificationEventListener
{
    public function __construct(
        private OutboxWriterInterface $outboxWriter,
    ) {
    }

    public function __invoke(OrderCancellationFinalizedEvent $event): void
    {
        $order = $event->order;

        $message = new OrderNotificationMessage(
            orderId: $order->id()->value,
            type: 'order_cancelled',
            buyerId: $order->buyerId()->value,
            payload: [],
        );

        $this->outboxWriter->append($message);
    }
}

