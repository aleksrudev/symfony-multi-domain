<?php

declare(strict_types=1);

namespace Tests\Unit\Ordering\Domain;

use App\Ordering\Domain\Action\CreateDraftAction;
use App\Ordering\Domain\Action\ReplaceOrderItemsAction;
use App\Ordering\Domain\Collection\OrderItemCollection;
use App\Ordering\Domain\DataProvider\OrderDataProviderInterface;
use App\Ordering\Domain\Entity\Order;
use App\Ordering\Domain\Entity\OrderItem;
use App\Ordering\Domain\Exception\OrderNotFoundException;
use App\Ordering\Domain\Repository\OrderRepositoryInterface;
use App\Ordering\Domain\ValueObject\BuyerId;
use App\Ordering\Domain\ValueObject\OrderId;
use App\Ordering\Domain\ValueObject\ProductId;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(CreateDraftAction::class)]
#[CoversClass(ReplaceOrderItemsAction::class)]
final class ActionTest extends TestCase
{
    public function testCreateDraftActionReturnsExistingDraftIfFound(): void
    {
        $buyerId = new BuyerId('buyer-1');
        $existingOrder = Order::createDraft(new OrderId('existing-order'), $buyerId);

        $dataProvider = $this->createMock(OrderDataProviderInterface::class);
        $dataProvider->expects(self::once())
            ->method('findDraftByBuyerId')
            ->with($buyerId)
            ->willReturn($existingOrder);

        $repository = $this->createMock(OrderRepositoryInterface::class);
        $repository->expects(self::never())->method('save');

        $action = new CreateDraftAction($dataProvider, $repository);
        $result = $action($buyerId);

        self::assertSame($existingOrder, $result);
    }

    public function testCreateDraftActionCreatesNewDraftIfNotFound(): void
    {
        $buyerId = new BuyerId('buyer-1');
        $newOrderId = new OrderId('new-order');

        $dataProvider = $this->createMock(OrderDataProviderInterface::class);
        $dataProvider->expects(self::once())
            ->method('findDraftByBuyerId')
            ->with($buyerId)
            ->willThrowException(new OrderNotFoundException('draft'));

        $repository = $this->createMock(OrderRepositoryInterface::class);
        $repository->expects(self::once())
            ->method('save')
            ->with(self::callback(function (Order $order) use ($buyerId, $newOrderId): bool {
                return $order->id()->equals($newOrderId) && $order->buyerId()->equals($buyerId);
            }));

        $action = new CreateDraftAction($dataProvider, $repository);
        $result = $action($buyerId, $newOrderId);

        self::assertTrue($result->id()->equals($newOrderId));
        self::assertTrue($result->buyerId()->equals($buyerId));
    }

    public function testReplaceOrderItemsActionReplacesAndSaves(): void
    {
        $orderId = new OrderId('ord-1');
        $buyerId = new BuyerId('buyer-1');
        $order = Order::createDraft($orderId, $buyerId);

        $dataProvider = $this->createMock(OrderDataProviderInterface::class);
        $dataProvider->expects(self::once())
            ->method('findByIdAndBuyerId')
            ->with($orderId, $buyerId)
            ->willReturn($order);

        $repository = $this->createMock(OrderRepositoryInterface::class);
        $repository->expects(self::once())
            ->method('save')
            ->with($order);

        $action = new ReplaceOrderItemsAction($dataProvider, $repository);
        $items = new OrderItemCollection([
            new OrderItem(new ProductId('prod-1'), 3),
        ]);

        $result = $action($orderId, $buyerId, $items);

        self::assertCount(1, $result->items());
        self::assertSame('prod-1', $result->items()->all()[0]->productId()->value);
        self::assertSame(3, $result->items()->all()[0]->quantity());
    }
}
