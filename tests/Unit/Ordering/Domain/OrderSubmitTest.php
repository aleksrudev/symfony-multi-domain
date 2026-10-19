<?php

declare(strict_types=1);

namespace Tests\Unit\Ordering\Domain;

use App\Ordering\Domain\Collection\OrderItemCollection;
use App\Ordering\Domain\Collection\ProductSnapshotCollection;
use App\Ordering\Domain\Entity\Order;
use App\Ordering\Domain\Entity\OrderItem;
use App\Ordering\Domain\Enum\Currency;
use App\Ordering\Domain\Enum\OrderStatus;
use App\Ordering\Domain\Exception\EmptyOrderItemsException;
use App\Ordering\Domain\Exception\OrderNotModifiableException;
use App\Ordering\Domain\Exception\ProductNotAvailableException;
use App\Ordering\Domain\ValueObject\BuyerId;
use App\Ordering\Domain\ValueObject\OrderId;
use App\Ordering\Domain\ValueObject\ProductId;
use App\Ordering\Domain\ValueObject\ProductSnapshot;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Order::class)]
#[CoversClass(OrderItem::class)]
final class OrderSubmitTest extends TestCase
{
    public function testSubmitDraftOrderCalculatesTotalAndStoresSnapshots(): void
    {
        $order = Order::createDraft(
            new OrderId('00000000-0000-4000-8000-000000000123'),
            new BuyerId('buyer-1'),
        );

        $order->replaceItems(new OrderItemCollection([
            new OrderItem(new ProductId('prod-keyboard'), 1),
            new OrderItem(new ProductId('prod-mouse'), 2),
        ]));

        $snapshots = new ProductSnapshotCollection([
            new ProductSnapshot(new ProductId('prod-keyboard'), 'Механическая клавиатура', 799000, Currency::RUB),
            new ProductSnapshot(new ProductId('prod-mouse'), 'Беспроводная мышь', 349000, Currency::RUB),
        ]);

        $order->submit($snapshots);

        self::assertSame(OrderStatus::PENDING_STOCK, $order->status());
        self::assertSame(1497000, $order->totalAmount());
        self::assertSame(Currency::RUB, $order->currency());

        $items = $order->items()->all();
        self::assertCount(2, $items);

        self::assertSame('prod-keyboard', $items[0]->productId()->value);
        self::assertSame(1, $items[0]->quantity());
        self::assertSame('Механическая клавиатура', $items[0]->productName());
        self::assertSame(799000, $items[0]->priceAmount());
        self::assertSame(Currency::RUB, $items[0]->currency());

        self::assertSame('prod-mouse', $items[1]->productId()->value);
        self::assertSame(2, $items[1]->quantity());
        self::assertSame('Беспроводная мышь', $items[1]->productName());
        self::assertSame(349000, $items[1]->priceAmount());
        self::assertSame(Currency::RUB, $items[1]->currency());
    }

    public function testSubmitNonDraftOrderThrowsException(): void
    {
        $order = new Order(
            id: new OrderId('00000000-0000-4000-8000-000000000123'),
            buyerId: new BuyerId('buyer-1'),
            status: OrderStatus::PENDING_STOCK,
        );

        $snapshots = new ProductSnapshotCollection([]);

        $this->expectException(OrderNotModifiableException::class);
        $order->submit($snapshots);
    }

    public function testSubmitEmptyOrderThrowsException(): void
    {
        $order = Order::createDraft(
            new OrderId('00000000-0000-4000-8000-000000000123'),
            new BuyerId('buyer-1'),
        );

        $snapshots = new ProductSnapshotCollection([]);

        $this->expectException(EmptyOrderItemsException::class);
        $order->submit($snapshots);
    }

    public function testSubmitWithMissingSnapshotThrowsException(): void
    {
        $order = Order::createDraft(
            new OrderId('00000000-0000-4000-8000-000000000123'),
            new BuyerId('buyer-1'),
        );

        $order->replaceItems(new OrderItemCollection([
            new OrderItem(new ProductId('prod-keyboard'), 1),
        ]));

        $snapshots = new ProductSnapshotCollection([]);

        $this->expectException(ProductNotAvailableException::class);
        $order->submit($snapshots);
    }
}

