<?php

declare(strict_types=1);

namespace App\Ordering\Infrastructure\Messaging\EventListener;

use App\Ordering\Domain\Event\OrderSubmittedEvent as DomainOrderSubmittedEvent;
use App\Ordering\Presentation\Contracts\Dto\SubmittedOrderItem;
use App\Ordering\Presentation\Contracts\Event\OrderSubmittedEvent as PublicOrderSubmittedEvent;
use App\Shared\Infrastructure\Messaging\IntegrationEvent\IntegrationEventDispatcherInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Uid\Uuid;

#[AsEventListener(event: DomainOrderSubmittedEvent::class)]
final readonly class PublishOrderSubmittedEventListener
{
    public function __construct(
        private IntegrationEventDispatcherInterface $dispatcher,
    ) {
    }

    public function __invoke(DomainOrderSubmittedEvent $event): void
    {
        $order = $event->order;

        $totalAmount = $order->totalAmount();
        $currency = $order->currency();
        if (null === $totalAmount || null === $currency) {
            throw new \LogicException(sprintf('Order "%s" must have totalAmount and currency when submitted.', $order->id()->value));
        }

        $items = [];
        foreach ($order->items() as $item) {
            $name = $item->productName();
            $price = $item->priceAmount();
            if (null === $name || null === $price) {
                throw new \LogicException(sprintf('Order item "%s" must have snapshot name and price when submitted.', $item->productId()->value));
            }

            $items[] = new SubmittedOrderItem(
                productId: $item->productId()->value,
                quantity: $item->quantity(),
                productName: $name,
                priceAmount: $price,
            );
        }

        $publicEvent = new PublicOrderSubmittedEvent(
            eventId: Uuid::v7()->toRfc4122(),
            eventAt: $event->eventAt->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.u\Z'),
            type: 'ordering.order_submitted',
            source: 'Ordering',
            schemaVersion: 1,
            orderId: $order->id()->value,
            buyerId: $order->buyerId()->value,
            items: $items,
            totalAmount: $totalAmount,
            currency: $currency->value,
        );

        $this->dispatcher->dispatch($publicEvent);
    }
}
