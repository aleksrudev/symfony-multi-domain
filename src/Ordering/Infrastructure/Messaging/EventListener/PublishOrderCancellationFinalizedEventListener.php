<?php

declare(strict_types=1);

namespace App\Ordering\Infrastructure\Messaging\EventListener;

use App\Ordering\Domain\Event\OrderCancellationFinalizedEvent as DomainOrderCancellationFinalizedEvent;
use App\Ordering\Presentation\Contracts\Event\OrderCancellationFinalizedEvent as PublicOrderCancellationFinalizedEvent;
use App\Shared\Infrastructure\Messaging\IntegrationEvent\IntegrationEventDispatcherInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Uid\Uuid;

#[AsEventListener(event: DomainOrderCancellationFinalizedEvent::class)]
final readonly class PublishOrderCancellationFinalizedEventListener
{
    public function __construct(
        private IntegrationEventDispatcherInterface $dispatcher,
    ) {
    }

    public function __invoke(DomainOrderCancellationFinalizedEvent $event): void
    {
        $order = $event->order;

        $publicEvent = new PublicOrderCancellationFinalizedEvent(
            eventId: Uuid::v7()->toRfc4122(),
            eventAt: $event->eventAt->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.u\Z'),
            type: 'ordering.order_cancellation_finalized',
            source: 'Ordering',
            schemaVersion: 1,
            orderId: $order->id()->value,
            buyerId: $order->buyerId()->value,
        );

        $this->dispatcher->dispatch($publicEvent);
    }
}

