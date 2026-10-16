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
        $orderId = new OrderId('order-123');
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
        $order = Order::createDraft(new OrderId('order-123'), new BuyerId('buyer-1'));

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
            new OrderId('order-123'),
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
        $order = Order::createDraft(new OrderId('order-123'), new BuyerId('buyer-1'));

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
}
