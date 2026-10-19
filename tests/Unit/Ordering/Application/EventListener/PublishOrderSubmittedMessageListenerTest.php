<?php

declare(strict_types=1);

namespace Tests\Unit\Ordering\Application\EventListener;

use App\Ordering\Application\EventListener\PublishOrderSubmittedMessageListener;
use App\Ordering\Application\Message\OrderSubmittedOutboxMessage;
use App\Ordering\Domain\Collection\OrderItemCollection;
use App\Ordering\Domain\Collection\ProductSnapshotCollection;
use App\Ordering\Domain\Entity\Order;
use App\Ordering\Domain\Entity\OrderItem;
use App\Ordering\Domain\Enum\Currency;
use App\Ordering\Domain\Event\OrderSubmittedEvent;
use App\Ordering\Domain\ValueObject\BuyerId;
use App\Ordering\Domain\ValueObject\OrderId;
use App\Ordering\Domain\ValueObject\ProductId;
use App\Ordering\Domain\ValueObject\ProductSnapshot;
use App\Shared\Application\Bus\Outbox\OutboxBusInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(PublishOrderSubmittedMessageListener::class)]
final class PublishOrderSubmittedMessageListenerTest extends TestCase
{
    public function testListenerPublishesOutboxMessage(): void
    {
        $order = Order::createDraft(
            new OrderId('00000000-0000-4000-8000-000000000001'),
            new BuyerId('buyer-1'),
        );
        $order->replaceItems(new OrderItemCollection([
            new OrderItem(new ProductId('prod-1'), 2),
        ]));
        $order->submit(new ProductSnapshotCollection([
            new ProductSnapshot(new ProductId('prod-1'), 'Product 1', 10000, Currency::RUB),
        ]));

        $eventAt = new \DateTimeImmutable('2026-10-19 12:00:00');
        $event = new OrderSubmittedEvent($order, $eventAt);

        $outboxBus = $this->createMock(OutboxBusInterface::class);
        $outboxBus->expects(self::once())
            ->method('publish')
            ->with(self::callback(static function (object $message) use ($eventAt): bool {
                return $message instanceof OrderSubmittedOutboxMessage
                    && '00000000-0000-4000-8000-000000000001' === $message->orderId
                    && 'buyer-1' === $message->buyerId
                    && 20000 === $message->totalAmount
                    && 'RUB' === $message->currency
                    && $message->items === [['productId' => 'prod-1', 'quantity' => 2]]
                    && $message->occurredAt === $eventAt->format(\DateTimeInterface::ATOM);
            }));

        $listener = new PublishOrderSubmittedMessageListener($outboxBus);
        $listener($event);
    }
}
