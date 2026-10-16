<?php

declare(strict_types=1);

namespace Tests\Unit\Ordering\Application;

use App\Ordering\Application\Command\CreateDraft\CreateDraftCommand;
use App\Ordering\Application\Command\CreateDraft\CreateDraftHandler;
use App\Ordering\Application\Command\ReplaceOrderItems\ReplaceOrderItemsCommand;
use App\Ordering\Application\Command\ReplaceOrderItems\ReplaceOrderItemsHandler;
use App\Ordering\Application\Query\GetDraft\GetDraftHandler;
use App\Ordering\Application\Query\GetDraft\GetDraftQuery;
use App\Ordering\Application\Query\GetOrder\GetOrderHandler;
use App\Ordering\Application\Query\GetOrder\GetOrderQuery;
use App\Ordering\Application\Query\GetOrders\GetOrdersHandler;
use App\Ordering\Application\Query\GetOrders\GetOrdersQuery;
use App\Ordering\Domain\Action\CreateDraftAction;
use App\Ordering\Domain\Action\ReplaceOrderItemsAction;
use App\Ordering\Domain\Collection\OrderCollection;
use App\Ordering\Domain\Collection\OrderItemCollection;
use App\Ordering\Domain\Collection\OrderPaginatedCollection;
use App\Ordering\Domain\DataProvider\IdempotencyKeyDataProviderInterface;
use App\Ordering\Domain\DataProvider\OrderDataProviderInterface;
use App\Ordering\Domain\Entity\IdempotencyKey;
use App\Ordering\Domain\Entity\Order;
use App\Ordering\Domain\Entity\OrderItem;
use App\Ordering\Domain\Exception\IdempotencyConflictException;
use App\Ordering\Domain\Exception\IdempotencyKeyNotFoundException;
use App\Ordering\Domain\Repository\IdempotencyKeyRepositoryInterface;
use App\Ordering\Domain\Repository\OrderRepositoryInterface;
use App\Ordering\Domain\ValueObject\BuyerId;
use App\Ordering\Domain\ValueObject\OrderId;
use App\Ordering\Domain\ValueObject\ProductId;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(CreateDraftHandler::class)]
#[CoversClass(ReplaceOrderItemsHandler::class)]
#[CoversClass(GetOrderHandler::class)]
#[CoversClass(GetDraftHandler::class)]
#[CoversClass(GetOrdersHandler::class)]
final class HandlerTest extends TestCase
{
    public function testCreateDraftHandlerWithNewKeySavesKeyAndExecutesAction(): void
    {
        $existingOrder = Order::createDraft(new OrderId('ord-1'), new BuyerId('buyer-1'));
        $dataProvider = $this->createMock(OrderDataProviderInterface::class);
        $dataProvider->expects(self::once())
            ->method('findDraftByBuyerId')
            ->willReturn($existingOrder);

        $repository = $this->createMock(OrderRepositoryInterface::class);
        $action = new CreateDraftAction($dataProvider, $repository);

        $idempotencyDataProvider = $this->createMock(IdempotencyKeyDataProviderInterface::class);
        $idempotencyDataProvider->expects(self::once())
            ->method('findByKeyAndBuyerId')
            ->with('key-1', self::callback(fn (BuyerId $b): bool => 'buyer-1' === (string) $b))
            ->willThrowException(new IdempotencyKeyNotFoundException('key-1'));

        $idempotencyRepo = $this->createMock(IdempotencyKeyRepositoryInterface::class);
        $idempotencyRepo->expects(self::once())
            ->method('save')
            ->with(self::callback(fn (IdempotencyKey $k): bool => 'key-1' === $k->key() && 'buyer-1' === (string) $k->buyerId()));

        $handler = new CreateDraftHandler($action, $idempotencyDataProvider, $idempotencyRepo);
        $handler(new CreateDraftCommand('buyer-1', 'key-1', 'hash-1'));
    }

    public function testCreateDraftHandlerWithExistingMatchingKeySkipsAction(): void
    {
        $dataProvider = $this->createMock(OrderDataProviderInterface::class);
        $dataProvider->expects(self::never())->method('findDraftByBuyerId');

        $repository = $this->createMock(OrderRepositoryInterface::class);
        $action = new CreateDraftAction($dataProvider, $repository);

        $existingKey = new IdempotencyKey('key-1', new BuyerId('buyer-1'), 'create_draft', 'hash-1');
        $idempotencyDataProvider = $this->createMock(IdempotencyKeyDataProviderInterface::class);
        $idempotencyDataProvider->expects(self::once())
            ->method('findByKeyAndBuyerId')
            ->willReturn($existingKey);

        $idempotencyRepo = $this->createMock(IdempotencyKeyRepositoryInterface::class);
        $idempotencyRepo->expects(self::never())->method('save');

        $handler = new CreateDraftHandler($action, $idempotencyDataProvider, $idempotencyRepo);
        $handler(new CreateDraftCommand('buyer-1', 'key-1', 'hash-1'));
    }

    public function testCreateDraftHandlerWithConflictingKeyThrowsException(): void
    {
        $dataProvider = $this->createMock(OrderDataProviderInterface::class);
        $dataProvider->expects(self::never())->method('findDraftByBuyerId');

        $repository = $this->createMock(OrderRepositoryInterface::class);
        $action = new CreateDraftAction($dataProvider, $repository);

        $existingKey = new IdempotencyKey('key-1', new BuyerId('buyer-1'), 'create_draft', 'hash-1');
        $idempotencyDataProvider = $this->createMock(IdempotencyKeyDataProviderInterface::class);
        $idempotencyDataProvider->expects(self::once())
            ->method('findByKeyAndBuyerId')
            ->willReturn($existingKey);

        $idempotencyRepo = $this->createMock(IdempotencyKeyRepositoryInterface::class);

        $handler = new CreateDraftHandler($action, $idempotencyDataProvider, $idempotencyRepo);

        $this->expectException(IdempotencyConflictException::class);
        $handler(new CreateDraftCommand('buyer-1', 'key-1', 'different-hash'));
    }

    public function testCreateDraftHandlerWithoutKeyExecutesAction(): void
    {
        $existingOrder = Order::createDraft(new OrderId('ord-1'), new BuyerId('buyer-1'));
        $dataProvider = $this->createMock(OrderDataProviderInterface::class);
        $dataProvider->expects(self::once())
            ->method('findDraftByBuyerId')
            ->willReturn($existingOrder);

        $repository = $this->createMock(OrderRepositoryInterface::class);
        $action = new CreateDraftAction($dataProvider, $repository);

        $idempotencyDataProvider = $this->createMock(IdempotencyKeyDataProviderInterface::class);
        $idempotencyDataProvider->expects(self::never())->method('findByKeyAndBuyerId');

        $idempotencyRepo = $this->createMock(IdempotencyKeyRepositoryInterface::class);
        $idempotencyRepo->expects(self::never())->method('save');

        $handler = new CreateDraftHandler($action, $idempotencyDataProvider, $idempotencyRepo);
        $handler(new CreateDraftCommand('buyer-1', null, 'hash-1'));
    }

    public function testReplaceOrderItemsHandler(): void
    {
        $order = Order::createDraft(new OrderId('ord-1'), new BuyerId('buyer-1'));
        $dataProvider = $this->createMock(OrderDataProviderInterface::class);
        $dataProvider->expects(self::once())
            ->method('findByIdAndBuyerId')
            ->willReturn($order);

        $repository = $this->createMock(OrderRepositoryInterface::class);
        $repository->expects(self::once())->method('save')->with($order);

        $action = new ReplaceOrderItemsAction($dataProvider, $repository);
        $handler = new ReplaceOrderItemsHandler($action);

        $handler(new ReplaceOrderItemsCommand('ord-1', 'buyer-1', [
            ['productId' => 'prod-1', 'quantity' => 2],
        ]));

        self::assertCount(1, $order->items());
    }

    public function testGetOrderHandler(): void
    {
        $order = Order::createDraft(new OrderId('ord-1'), new BuyerId('buyer-1'));
        $order->replaceItems(new OrderItemCollection([
            new OrderItem(new ProductId('prod-1'), 5),
        ]));

        $dataProvider = $this->createMock(OrderDataProviderInterface::class);
        $dataProvider->expects(self::once())
            ->method('findByIdAndBuyerId')
            ->willReturn($order);

        $handler = new GetOrderHandler($dataProvider);
        $result = $handler(new GetOrderQuery('ord-1', 'buyer-1'));

        self::assertSame('ord-1', $result->id);
        self::assertSame('buyer-1', $result->buyerId);
        self::assertCount(1, $result->items);
        self::assertSame('prod-1', $result->items[0]['productId']);
        self::assertSame(5, $result->items[0]['quantity']);
    }

    public function testGetDraftHandler(): void
    {
        $order = Order::createDraft(new OrderId('ord-draft'), new BuyerId('buyer-1'));

        $dataProvider = $this->createMock(OrderDataProviderInterface::class);
        $dataProvider->expects(self::once())
            ->method('findDraftByBuyerId')
            ->willReturn($order);

        $handler = new GetDraftHandler($dataProvider);
        $result = $handler(new GetDraftQuery('buyer-1'));

        self::assertSame('ord-draft', $result->id);
        self::assertSame('draft', $result->status);
    }

    public function testGetOrdersHandler(): void
    {
        $order = Order::createDraft(new OrderId('ord-1'), new BuyerId('buyer-1'));
        $paginated = new OrderPaginatedCollection(new OrderCollection([$order]), 1);

        $dataProvider = $this->createMock(OrderDataProviderInterface::class);
        $dataProvider->expects(self::once())
            ->method('findByBuyerIdPaginated')
            ->with(self::callback(fn (BuyerId $b) => 'buyer-1' === $b->value), 0, 10)
            ->willReturn($paginated);

        $handler = new GetOrdersHandler($dataProvider);
        $result = $handler(new GetOrdersQuery('buyer-1', 1, 10));

        self::assertSame(1, $result->total);
        self::assertCount(1, $result->items);
        self::assertSame('ord-1', $result->items[0]->id);
    }
}
