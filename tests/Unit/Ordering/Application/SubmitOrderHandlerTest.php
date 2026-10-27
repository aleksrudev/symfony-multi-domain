<?php

declare(strict_types=1);

namespace Tests\Unit\Ordering\Application;

use App\Ordering\Application\Command\SubmitOrder\Provider\ProductOffer;
use App\Ordering\Application\Command\SubmitOrder\Provider\ProductOfferProviderInterface;
use App\Ordering\Application\Command\SubmitOrder\SubmitOrderCommand;
use App\Ordering\Application\Command\SubmitOrder\SubmitOrderHandler;
use App\Ordering\Domain\Action\SubmitOrderAction;
use App\Ordering\Domain\Collection\OrderItemCollection;
use App\Ordering\Domain\DataProvider\IdempotencyKeyDataProviderInterface;
use App\Ordering\Domain\DataProvider\OrderDataProviderInterface;
use App\Ordering\Domain\Entity\IdempotencyKey;
use App\Ordering\Domain\Entity\Order;
use App\Ordering\Domain\Entity\OrderItem;
use App\Ordering\Domain\Enum\IdempotencyOperation;
use App\Ordering\Domain\Enum\OrderStatus;
use App\Ordering\Domain\Exception\IdempotencyConflictException;
use App\Ordering\Domain\Exception\IdempotencyKeyNotFoundException;
use App\Ordering\Domain\Exception\ProductNotAvailableException;
use App\Ordering\Domain\Repository\IdempotencyKeyRepositoryInterface;
use App\Ordering\Domain\Repository\OrderRepositoryInterface;
use App\Ordering\Domain\ValueObject\BuyerId;
use App\Ordering\Domain\ValueObject\OrderId;
use App\Ordering\Domain\ValueObject\ProductId;
use App\Shared\Domain\Event\EventBusInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(SubmitOrderHandler::class)]
final class SubmitOrderHandlerTest extends TestCase
{
    private const ORDER_ID = '00000000-0000-4000-8000-000000000001';

    public function testSubmitOrderSuccessful(): void
    {
        $order = Order::createDraft(new OrderId(self::ORDER_ID), new BuyerId('buyer-1'));
        $order->replaceItems(new OrderItemCollection([
            new OrderItem(new ProductId('prod-1'), 2),
        ]));

        $dataProvider = $this->createMock(OrderDataProviderInterface::class);
        $dataProvider->expects(self::once())
            ->method('findByIdAndBuyerId')
            ->willReturn($order);

        $orderRepository = $this->createMock(OrderRepositoryInterface::class);
        $orderRepository->expects(self::once())
            ->method('save')
            ->with($order);

        $eventBus = $this->createMock(EventBusInterface::class);
        $eventBus->expects(self::once())
            ->method('dispatch');

        $action = new SubmitOrderAction($orderRepository, $eventBus);

        $offerProvider = $this->createMock(ProductOfferProviderInterface::class);
        $offerProvider->expects(self::once())
            ->method('getOffer')
            ->with('prod-1')
            ->willReturn(new ProductOffer('prod-1', 'Product 1', 10000, 'RUB'));

        $idempotencyDataProvider = $this->createMock(IdempotencyKeyDataProviderInterface::class);
        $idempotencyDataProvider->expects(self::once())
            ->method('findByKeyAndBuyerId')
            ->willThrowException(new IdempotencyKeyNotFoundException('key-1'));

        $idempotencyRepository = $this->createMock(IdempotencyKeyRepositoryInterface::class);
        $idempotencyRepository->expects(self::once())
            ->method('save')
            ->with(self::isInstanceOf(IdempotencyKey::class));

        $handler = new SubmitOrderHandler(
            $dataProvider,
            $offerProvider,
            $action,
            $idempotencyDataProvider,
            $idempotencyRepository,
        );

        $command = new SubmitOrderCommand(
            orderId: self::ORDER_ID,
            buyerId: 'buyer-1',
            idempotencyKey: 'key-1',
            requestHash: 'hash-abc',
        );

        $handler($command);

        self::assertSame(OrderStatus::PENDING_STOCK, $order->status());
        self::assertSame(20000, $order->totalAmount());
    }

    public function testSubmitOrderWithMissingOfferThrowsException(): void
    {
        $order = Order::createDraft(new OrderId(self::ORDER_ID), new BuyerId('buyer-1'));
        $order->replaceItems(new OrderItemCollection([
            new OrderItem(new ProductId('prod-missing'), 1),
        ]));

        $dataProvider = $this->createMock(OrderDataProviderInterface::class);
        $dataProvider->expects(self::once())
            ->method('findByIdAndBuyerId')
            ->willReturn($order);

        $orderRepository = $this->createMock(OrderRepositoryInterface::class);
        $eventBus = $this->createMock(EventBusInterface::class);
        $action = new SubmitOrderAction($orderRepository, $eventBus);

        $offerProvider = $this->createMock(ProductOfferProviderInterface::class);
        $offerProvider->expects(self::once())
            ->method('getOffer')
            ->with('prod-missing')
            ->willReturn(null);

        $idempotencyDataProvider = $this->createMock(IdempotencyKeyDataProviderInterface::class);
        $idempotencyRepository = $this->createMock(IdempotencyKeyRepositoryInterface::class);

        $handler = new SubmitOrderHandler(
            $dataProvider,
            $offerProvider,
            $action,
            $idempotencyDataProvider,
            $idempotencyRepository,
        );

        $command = new SubmitOrderCommand(
            orderId: self::ORDER_ID,
            buyerId: 'buyer-1',
        );

        $this->expectException(ProductNotAvailableException::class);
        $handler($command);
    }

    public function testSubmitOrderWithMatchingIdempotencyKeyReturnsEarly(): void
    {
        $existingKey = new IdempotencyKey(
            key: 'idem-key-1',
            buyerId: new BuyerId('buyer-1'),
            operation: IdempotencyOperation::SUBMIT_ORDER,
            requestHash: 'hash-matching',
        );

        $dataProvider = $this->createMock(OrderDataProviderInterface::class);
        $dataProvider->expects(self::never())->method('findByIdAndBuyerId');

        $orderRepository = $this->createMock(OrderRepositoryInterface::class);
        $eventBus = $this->createMock(EventBusInterface::class);
        $eventBus->expects(self::never())->method('dispatch');
        $action = new SubmitOrderAction($orderRepository, $eventBus);

        $offerProvider = $this->createMock(ProductOfferProviderInterface::class);
        $offerProvider->expects(self::never())->method('getOffer');

        $idempotencyDataProvider = $this->createMock(IdempotencyKeyDataProviderInterface::class);
        $idempotencyDataProvider->expects(self::once())
            ->method('findByKeyAndBuyerId')
            ->willReturn($existingKey);

        $idempotencyRepository = $this->createMock(IdempotencyKeyRepositoryInterface::class);
        $idempotencyRepository->expects(self::never())->method('save');

        $handler = new SubmitOrderHandler(
            $dataProvider,
            $offerProvider,
            $action,
            $idempotencyDataProvider,
            $idempotencyRepository,
        );

        $command = new SubmitOrderCommand(
            orderId: self::ORDER_ID,
            buyerId: 'buyer-1',
            idempotencyKey: 'idem-key-1',
            requestHash: 'hash-matching',
        );

        $handler($command);
    }

    public function testSubmitOrderWithConflictingIdempotencyKeyThrowsConflict(): void
    {
        $existingKey = new IdempotencyKey(
            key: 'idem-key-1',
            buyerId: new BuyerId('buyer-1'),
            operation: IdempotencyOperation::SUBMIT_ORDER,
            requestHash: 'hash-original',
        );

        $dataProvider = $this->createMock(OrderDataProviderInterface::class);
        $orderRepository = $this->createMock(OrderRepositoryInterface::class);
        $eventBus = $this->createMock(EventBusInterface::class);
        $action = new SubmitOrderAction($orderRepository, $eventBus);
        $offerProvider = $this->createMock(ProductOfferProviderInterface::class);

        $idempotencyDataProvider = $this->createMock(IdempotencyKeyDataProviderInterface::class);
        $idempotencyDataProvider->expects(self::once())
            ->method('findByKeyAndBuyerId')
            ->willReturn($existingKey);

        $idempotencyRepository = $this->createMock(IdempotencyKeyRepositoryInterface::class);

        $handler = new SubmitOrderHandler(
            $dataProvider,
            $offerProvider,
            $action,
            $idempotencyDataProvider,
            $idempotencyRepository,
        );

        $command = new SubmitOrderCommand(
            orderId: self::ORDER_ID,
            buyerId: 'buyer-1',
            idempotencyKey: 'idem-key-1',
            requestHash: 'hash-different',
        );

        $this->expectException(IdempotencyConflictException::class);
        $handler($command);
    }
}
