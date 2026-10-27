<?php

declare(strict_types=1);

namespace App\Ordering\Infrastructure\Messaging\EventListener;

use App\Ordering\Domain\Event\OrderRejectedEvent as DomainOrderRejectedEvent;
use App\Ordering\Presentation\Contracts\Event\OrderRejectedEvent as PublicOrderRejectedEvent;
use App\Shared\Infrastructure\Messaging\IntegrationEvent\IntegrationEventDispatcherInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Uid\Uuid;

#[AsEventListener(event: DomainOrderRejectedEvent::class)]
final readonly class PublishOrderRejectedEventListener
{
    public function __construct(
        private IntegrationEventDispatcherInterface $dispatcher,
    ) {
    }

    public function __invoke(DomainOrderRejectedEvent $event): void
    {
        $order = $event->order;

        $publicEvent = new PublicOrderRejectedEvent(
            eventId: Uuid::v7()->toRfc4122(),
            eventAt: $event->eventAt->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.u\Z'),
            type: 'ordering.order_rejected',
            source: 'Ordering',
            schemaVersion: 1,
            orderId: $order->id()->value,
            buyerId: $order->buyerId()->value,
            reason: $event->reason,
        );

        $this->dispatcher->dispatch($publicEvent);
    }
}
