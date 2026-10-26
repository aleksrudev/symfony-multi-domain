<?php

declare(strict_types=1);

namespace App\Ordering\Infrastructure\Messaging\EventListener;

use App\Ordering\Domain\Event\OrderRejectedEvent;
use App\Ordering\Infrastructure\Messaging\Outbox\OrderNotification\OrderNotificationMessage;
use App\Shared\Infrastructure\Messaging\Outbox\Writer\OutboxWriterInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

#[AsEventListener(event: OrderRejectedEvent::class)]
final readonly class PublishOrderRejectedNotificationEventListener
{
    public function __construct(
        private OutboxWriterInterface $outboxWriter,
    ) {
    }

    public function __invoke(OrderRejectedEvent $event): void
    {
        $order = $event->order;

        $message = new OrderNotificationMessage(
            orderId: $order->id()->value,
            type: 'order_rejected',
            buyerId: $order->buyerId()->value,
            payload: [
                'reason' => $event->reason,
            ],
        );

        $this->outboxWriter->append($message);
    }
}
