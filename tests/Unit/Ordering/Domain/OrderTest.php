<?php

declare(strict_types=1);

namespace Tests\Unit\Ordering\Domain;

use App\Ordering\Domain\Collection\OrderItemCollection;
use App\Ordering\Domain\Entity\Order;
use App\Ordering\Domain\Entity\OrderItem;
use App\Ordering\Domain\Enum\OrderStatus;
use App\Ordering\Domain\Exception\EmptyOrderItemsException;
use App\Ordering\Domain\Exception\InvalidOrderItemQuantityException;
use App\Ordering\Domain\Exception\OrderNotModifiableException;
use App\Ordering\Domain\ValueObject\BuyerId;
use App\Ordering\Domain\ValueObject\OrderId;
use App\Ordering\Domain\ValueObject\ProductId;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Order::class)]
#[CoversClass(OrderItem::class)]
final class OrderTest extends TestCase
{
    public function testCreateDraftOrder(): void
    {
        $orderId = new OrderId('00000000-0000-4000-8000-000000000123');
        $buyerId = new BuyerId('buyer-1');

        $order = Order::createDraft($orderId, $buyerId);

        self::assertTrue($order->id()->equals($orderId));
        self::assertTrue($order->buyerId()->equals($buyerId));
        self::assertSame(OrderStatus::DRAFT, $order->status());
        self::assertTrue($order->items()->isEmpty());
        self::assertInstanceOf(\DateTimeImmutable::class, $order->createdAt());
        self::assertInstanceOf(\DateTimeImmutable::class, $order->updatedAt());
    }

    public function testReplaceItemsInDraft(): void
    {
        $order = Order::createDraft(new OrderId('00000000-0000-4000-8000-000000000123'), new BuyerId('buyer-1'));

        $items = new OrderItemCollection([
            new OrderItem(new ProductId('prod-1'), 2),
            new OrderItem(new ProductId('prod-2'), 1),
        ]);

        $order->replaceItems($items);

        self::assertCount(2, $order->items());
        $orderItems = $order->items()->all();
        self::assertSame('prod-1', $orderItems[0]->productId()->value);
        self::assertSame(2, $orderItems[0]->quantity());
        self::assertSame('prod-2', $orderItems[1]->productId()->value);
        self::assertSame(1, $orderItems[1]->quantity());
    }

    public function testReplaceItemsInNonDraftStatusThrowsException(): void
    {
        $order = new Order(
            new OrderId('00000000-0000-4000-8000-000000000123'),
            new BuyerId('buyer-1'),
            OrderStatus::PENDING_STOCK,
        );

        $this->expectException(OrderNotModifiableException::class);
        $order->replaceItems(new OrderItemCollection([
            new OrderItem(new ProductId('prod-1'), 1),
        ]));
    }

    public function testReplaceItemsWithEmptyCollectionThrowsException(): void
    {
        $order = Order::createDraft(new OrderId('00000000-0000-4000-8000-000000000123'), new BuyerId('buyer-1'));

        $this->expectException(EmptyOrderItemsException::class);
        $order->replaceItems(new OrderItemCollection([]));
    }

    public function testOrderItemWithZeroOrNegativeQuantityThrowsException(): void
    {
        $this->expectException(InvalidOrderItemQuantityException::class);
        new OrderItem(new ProductId('prod-1'), 0);
    }

    public function testOrderItemWithNegativeQuantityThrowsException(): void
    {
        $this->expectException(InvalidOrderItemQuantityException::class);
        new OrderItem(new ProductId('prod-1'), -5);
    }

    public function testConfirmOrderFromPendingStock(): void
    {
        $order = new Order(
            new OrderId('00000000-0000-4000-8000-000000000123'),
            new BuyerId('buyer-1'),
            OrderStatus::PENDING_STOCK,
            nextCheckAt: new \DateTimeImmutable('2026-10-26 12:00:00+00:00'),
        );

        $order->confirm();

        self::assertSame(OrderStatus::CONFIRMED, $order->status());
        self::assertNull($order->nextCheckAt());
        self::assertNull($order->rejectionReason());

        // Idempotent repeat
        $order->confirm();
        self::assertSame(OrderStatus::CONFIRMED, $order->status());
    }

    public function testRejectOrderFromPendingStock(): void
    {
        $order = new Order(
            new OrderId('00000000-0000-4000-8000-000000000123'),
            new BuyerId('buyer-1'),
            OrderStatus::PENDING_STOCK,
            nextCheckAt: new \DateTimeImmutable('2026-10-26 12:00:00+00:00'),
        );

        $order->reject('insufficient_stock');

        self::assertSame(OrderStatus::REJECTED, $order->status());
        self::assertSame('insufficient_stock', $order->rejectionReason());
        self::assertNull($order->nextCheckAt());

        // Idempotent repeat
        $order->reject('another_reason');
        self::assertSame(OrderStatus::REJECTED, $order->status());
        self::assertSame('insufficient_stock', $order->rejectionReason());
    }

    public function testConfirmOnDraftThrowsException(): void
    {
        $order = Order::createDraft(new OrderId('00000000-0000-4000-8000-000000000123'), new BuyerId('buyer-1'));

        $this->expectException(OrderNotModifiableException::class);
        $order->confirm();
    }

    public function testRejectOnDraftThrowsException(): void
    {
        $order = Order::createDraft(new OrderId('00000000-0000-4000-8000-000000000123'), new BuyerId('buyer-1'));

        $this->expectException(OrderNotModifiableException::class);
        $order->reject('some_reason');
    }

    public function testRescheduleCheckUpdatesNextCheckAtForPendingStock(): void
    {
        $order = new Order(
            new OrderId('00000000-0000-4000-8000-000000000123'),
            new BuyerId('buyer-1'),
            OrderStatus::PENDING_STOCK,
        );

        $nextCheck = new \DateTimeImmutable('2026-10-26 12:05:00+00:00');
        $order->rescheduleCheck($nextCheck);

        self::assertSame($nextCheck, $order->nextCheckAt());

        // Non-pending stock ignores reschedule
        $draft = Order::createDraft(new OrderId('00000000-0000-4000-8000-000000000123'), new BuyerId('buyer-1'));
        $draft->rescheduleCheck($nextCheck);
        self::assertNull($draft->nextCheckAt());
    }

    public function testCancelPendingStockOrder(): void
    {
        $order = new Order(
            new OrderId('00000000-0000-4000-8000-000000000123'),
            new BuyerId('buyer-1'),
            OrderStatus::PENDING_STOCK,
        );

        $order->cancel();

        self::assertSame(OrderStatus::CANCELLING, $order->status());
        self::assertNotNull($order->nextCheckAt());
    }

    public function testCancelConfirmedOrder(): void
    {
        $order = new Order(
            new OrderId('00000000-0000-4000-8000-000000000123'),
            new BuyerId('buyer-1'),
            OrderStatus::CONFIRMED,
        );

        $order->cancel();

        self::assertSame(OrderStatus::CANCELLING, $order->status());
        self::assertNotNull($order->nextCheckAt());
    }

    public function testCancelAlreadyCancellingOrCancelledIsIdempotent(): void
    {
        $order = new Order(
            new OrderId('00000000-0000-4000-8000-000000000123'),
            new BuyerId('buyer-1'),
            OrderStatus::CANCELLING,
        );

        $order->cancel();
        self::assertSame(OrderStatus::CANCELLING, $order->status());

        $cancelled = new Order(
            new OrderId('00000000-0000-4000-8000-000000000123'),
            new BuyerId('buyer-1'),
            OrderStatus::CANCELLED,
        );

        $cancelled->cancel();
        self::assertSame(OrderStatus::CANCELLED, $cancelled->status());
    }

    public function testCancelDraftThrowsException(): void
    {
        $order = Order::createDraft(new OrderId('00000000-0000-4000-8000-000000000123'), new BuyerId('buyer-1'));

        $this->expectException(OrderNotModifiableException::class);
        $order->cancel();
    }

    public function testCancelRejectedThrowsException(): void
    {
        $order = new Order(
            new OrderId('00000000-0000-4000-8000-000000000123'),
            new BuyerId('buyer-1'),
            OrderStatus::REJECTED,
        );

        $this->expectException(OrderNotModifiableException::class);
        $order->cancel();
    }

    public function testFinalizeCancellationFromCancelling(): void
    {
        $order = new Order(
            new OrderId('00000000-0000-4000-8000-000000000123'),
            new BuyerId('buyer-1'),
            OrderStatus::CANCELLING,
            nextCheckAt: new \DateTimeImmutable('now'),
        );

        $order->finalizeCancellation();

        self::assertSame(OrderStatus::CANCELLED, $order->status());
        self::assertNull($order->nextCheckAt());

        // Idempotent repeat
        $order->finalizeCancellation();
        self::assertSame(OrderStatus::CANCELLED, $order->status());
    }

    public function testFinalizeCancellationFromNonCancellingThrowsException(): void
    {
        $order = new Order(
            new OrderId('00000000-0000-4000-8000-000000000123'),
            new BuyerId('buyer-1'),
            OrderStatus::CONFIRMED,
        );

        $this->expectException(OrderNotModifiableException::class);
        $order->finalizeCancellation();
    }
}
