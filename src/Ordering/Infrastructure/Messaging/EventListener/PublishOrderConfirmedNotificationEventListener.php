<?php

declare(strict_types=1);

namespace App\Ordering\Infrastructure\Messaging\EventListener;

use App\Ordering\Domain\Event\OrderConfirmedEvent;
use App\Ordering\Infrastructure\Messaging\Outbox\OrderNotification\OrderNotificationMessage;
use App\Shared\Infrastructure\Messaging\Outbox\Writer\OutboxWriterInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

#[AsEventListener(event: OrderConfirmedEvent::class)]
final readonly class PublishOrderConfirmedNotificationEventListener
{
    public function __construct(
        private OutboxWriterInterface $outboxWriter,
    ) {
    }

    public function __invoke(OrderConfirmedEvent $event): void
    {
        $order = $event->order;

        $message = new OrderNotificationMessage(
            orderId: $order->id()->value,
            type: 'order_confirmed',
            buyerId: $order->buyerId()->value,
            payload: [
                'totalAmount' => $order->totalAmount(),
                'currency' => $order->currency()?->value,
            ],
        );

        $this->outboxWriter->append($message);
    }
}
