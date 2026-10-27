<?php

declare(strict_types=1);

namespace Tests\Unit\Ordering\Domain\Action;

use App\Ordering\Domain\Action\SubmitOrderAction;
use App\Ordering\Domain\Collection\OrderItemCollection;
use App\Ordering\Domain\Collection\ProductSnapshotCollection;
use App\Ordering\Domain\Entity\Order;
use App\Ordering\Domain\Entity\OrderItem;
use App\Ordering\Domain\Enum\Currency;
use App\Ordering\Domain\Enum\OrderStatus;
use App\Ordering\Domain\Event\OrderSubmittedEvent;
use App\Ordering\Domain\Repository\OrderRepositoryInterface;
use App\Ordering\Domain\ValueObject\BuyerId;
use App\Ordering\Domain\ValueObject\OrderId;
use App\Ordering\Domain\ValueObject\ProductId;
use App\Ordering\Domain\ValueObject\ProductSnapshot;
use App\Shared\Domain\Event\EventBusInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(SubmitOrderAction::class)]
final class SubmitOrderActionTest extends TestCase
{
    public function testSubmitOrderActionSavesOrderAndDispatchesEvent(): void
    {
        $order = Order::createDraft(
            new OrderId('00000000-0000-4000-8000-000000000001'),
            new BuyerId('buyer-1'),
        );
        $order->replaceItems(new OrderItemCollection([
            new OrderItem(new ProductId('prod-1'), 1),
        ]));

        $snapshots = new ProductSnapshotCollection([
            new ProductSnapshot(new ProductId('prod-1'), 'Product 1', 50000, Currency::RUB),
        ]);

        $orderRepository = $this->createMock(OrderRepositoryInterface::class);
        $orderRepository->expects(self::once())
            ->method('save')
            ->with($order);

        $eventBus = $this->createMock(EventBusInterface::class);
        $eventBus->expects(self::once())
            ->method('dispatch')
            ->with(self::callback(static function (object $event) use ($order): bool {
                return $event instanceof OrderSubmittedEvent
                    && $event->order === $order
                    && $event->eventAt == $order->updatedAt();
            }));

        $action = new SubmitOrderAction($orderRepository, $eventBus);
        $result = $action($order, $snapshots);

        self::assertSame($order, $result);
        self::assertSame(OrderStatus::PENDING_STOCK, $order->status());
        self::assertSame(50000, $order->totalAmount());
    }
}
