<?php

declare(strict_types=1);

namespace App\Ordering\Infrastructure\Messaging\EventListener;

use App\Ordering\Domain\Event\OrderCancellingEvent;
use App\Ordering\Presentation\Contracts\Event\OrderCancelledEvent;
use App\Shared\Infrastructure\Messaging\IntegrationEvent\IntegrationEventDispatcherInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Uid\Uuid;

#[AsEventListener(event: OrderCancellingEvent::class)]
final readonly class PublishOrderCancelledEventListener
{
    public function __construct(
        private IntegrationEventDispatcherInterface $dispatcher,
    ) {
    }

    public function __invoke(OrderCancellingEvent $event): void
    {
        $order = $event->order;

        $publicEvent = new OrderCancelledEvent(
            eventId: Uuid::v7()->toRfc4122(),
            eventAt: $event->eventAt->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.u\Z'),
            type: 'ordering.order_cancelled',
            source: 'Ordering',
            schemaVersion: 1,
            orderId: $order->id()->value,
            buyerId: $order->buyerId()->value,
        );

        $this->dispatcher->dispatch($publicEvent);
    }
}
