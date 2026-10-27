<?php

declare(strict_types=1);

namespace App\Ordering\Infrastructure\Messaging\EventListener;

use App\Ordering\Domain\Event\OrderConfirmedEvent as DomainOrderConfirmedEvent;
use App\Ordering\Presentation\Contracts\Event\OrderConfirmedEvent as PublicOrderConfirmedEvent;
use App\Shared\Infrastructure\Messaging\IntegrationEvent\IntegrationEventDispatcherInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Uid\Uuid;

#[AsEventListener(event: DomainOrderConfirmedEvent::class)]
final readonly class PublishOrderConfirmedEventListener
{
    public function __construct(
        private IntegrationEventDispatcherInterface $dispatcher,
    ) {
    }

    public function __invoke(DomainOrderConfirmedEvent $event): void
    {
        $order = $event->order;

        $totalAmount = $order->totalAmount();
        $currency = $order->currency();
        if (null === $totalAmount || null === $currency) {
            throw new \LogicException(sprintf('Order "%s" must have totalAmount and currency when confirmed.', $order->id()->value));
        }

        $publicEvent = new PublicOrderConfirmedEvent(
            eventId: Uuid::v7()->toRfc4122(),
            eventAt: $event->eventAt->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.u\Z'),
            type: 'ordering.order_confirmed',
            source: 'Ordering',
            schemaVersion: 1,
            orderId: $order->id()->value,
            buyerId: $order->buyerId()->value,
            totalAmount: $totalAmount,
            currency: $currency->value,
        );

        $this->dispatcher->dispatch($publicEvent);
    }
}
