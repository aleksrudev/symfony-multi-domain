<?php

declare(strict_types=1);

namespace Tests\Unit\Ordering\Application;

use App\Ordering\Application\Command\CancelOrder\CancelOrderCommand;
use App\Ordering\Application\Command\CancelOrder\CancelOrderHandler;
use App\Ordering\Domain\Action\CancelOrderAction;
use App\Ordering\Domain\DataProvider\IdempotencyKeyDataProviderInterface;
use App\Ordering\Domain\DataProvider\OrderDataProviderInterface;
use App\Ordering\Domain\Entity\IdempotencyKey;
use App\Ordering\Domain\Entity\Order;
use App\Ordering\Domain\Enum\IdempotencyOperation;
use App\Ordering\Domain\Enum\OrderStatus;
use App\Ordering\Domain\Exception\IdempotencyConflictException;
use App\Ordering\Domain\Exception\IdempotencyKeyNotFoundException;
use App\Ordering\Domain\Exception\OrderNotFoundException;
use App\Ordering\Domain\Repository\IdempotencyKeyRepositoryInterface;
use App\Ordering\Domain\Repository\OrderRepositoryInterface;
use App\Ordering\Domain\ValueObject\BuyerId;
use App\Ordering\Domain\ValueObject\OrderId;
use App\Shared\Domain\Event\EventBusInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(CancelOrderHandler::class)]
final class CancelOrderHandlerTest extends TestCase
{
    public function testCancelOrderInvokesActionAndSavesIdempotencyKey(): void
    {
        $orderId = new OrderId('00000000-0000-4000-8000-000000000010');
        $buyerId = new BuyerId('buyer-1');
        $order = new Order($orderId, $buyerId, OrderStatus::CONFIRMED);

        $orderDataProvider = $this->createMock(OrderDataProviderInterface::class);
        $orderDataProvider->expects(self::once())
            ->method('findById')
            ->with(self::callback(static fn (OrderId $id): bool => $id->value === $orderId->value), true)
            ->willReturn($order);

        $orderRepository = $this->createMock(OrderRepositoryInterface::class);
        $orderRepository->expects(self::once())
            ->method('save')
            ->with($order);

        $eventBus = $this->createMock(EventBusInterface::class);
        $eventBus->expects(self::once())
            ->method('dispatch');

        $cancelOrderAction = new CancelOrderAction($orderRepository, $eventBus);

        $idempotencyKeyDataProvider = $this->createMock(IdempotencyKeyDataProviderInterface::class);
        $idempotencyKeyDataProvider->expects(self::once())
            ->method('findByKeyAndBuyerId')
            ->with('idem-cancel-1', self::callback(static fn (BuyerId $bId): bool => 'buyer-1' === $bId->value))
            ->willThrowException(new IdempotencyKeyNotFoundException('idem-cancel-1'));

        $idempotencyKeyRepository = $this->createMock(IdempotencyKeyRepositoryInterface::class);
        $idempotencyKeyRepository->expects(self::once())
            ->method('save')
            ->with(self::callback(static fn (IdempotencyKey $k): bool => 'idem-cancel-1' === $k->key()
                && 'buyer-1' === $k->buyerId()->value
                && IdempotencyOperation::CANCEL_ORDER === $k->operation()));

        $handler = new CancelOrderHandler(
            $orderDataProvider,
            $cancelOrderAction,
            $idempotencyKeyDataProvider,
            $idempotencyKeyRepository,
        );

        $handler(new CancelOrderCommand(
            orderId: $orderId->value,
            buyerId: 'buyer-1',
            idempotencyKey: 'idem-cancel-1',
            requestHash: 'hash-abc',
        ));

        self::assertSame(OrderStatus::CANCELLING, $order->status());
    }

    public function testCancelOrderThrowsOrderNotFoundExceptionWhenBuyerDoesNotMatch(): void
    {
        $orderId = new OrderId('00000000-0000-4000-8000-000000000010');
        $order = new Order($orderId, new BuyerId('buyer-1'), OrderStatus::CONFIRMED);

        $orderDataProvider = $this->createMock(OrderDataProviderInterface::class);
        $orderDataProvider->expects(self::once())
            ->method('findById')
            ->willReturn($order);

        $orderRepository = $this->createMock(OrderRepositoryInterface::class);
        $eventBus = $this->createMock(EventBusInterface::class);
        $cancelOrderAction = new CancelOrderAction($orderRepository, $eventBus);

        $handler = new CancelOrderHandler($orderDataProvider, $cancelOrderAction);

        $this->expectException(OrderNotFoundException::class);
        $handler(new CancelOrderCommand(
            orderId: $orderId->value,
            buyerId: 'buyer-2',
        ));
    }

    public function testCancelOrderReplaysWhenIdempotencyKeyMatches(): void
    {
        $orderDataProvider = $this->createMock(OrderDataProviderInterface::class);
        $orderDataProvider->expects(self::never())->method('findById');

        $orderRepository = $this->createMock(OrderRepositoryInterface::class);
        $eventBus = $this->createMock(EventBusInterface::class);
        $cancelOrderAction = new CancelOrderAction($orderRepository, $eventBus);

        $existingKey = new IdempotencyKey(
            key: 'idem-cancel-1',
            buyerId: new BuyerId('buyer-1'),
            operation: IdempotencyOperation::CANCEL_ORDER,
            requestHash: 'hash-abc',
        );

        $idempotencyKeyDataProvider = $this->createMock(IdempotencyKeyDataProviderInterface::class);
        $idempotencyKeyDataProvider->expects(self::once())
            ->method('findByKeyAndBuyerId')
            ->willReturn($existingKey);

        $handler = new CancelOrderHandler(
            $orderDataProvider,
            $cancelOrderAction,
            $idempotencyKeyDataProvider,
        );

        $handler(new CancelOrderCommand(
            orderId: '00000000-0000-4000-8000-000000000010',
            buyerId: 'buyer-1',
            idempotencyKey: 'idem-cancel-1',
            requestHash: 'hash-abc',
        ));
    }

    public function testCancelOrderThrowsConflictWhenIdempotencyKeyHashDiffers(): void
    {
        $orderDataProvider = $this->createMock(OrderDataProviderInterface::class);
        $orderRepository = $this->createMock(OrderRepositoryInterface::class);
        $eventBus = $this->createMock(EventBusInterface::class);
        $cancelOrderAction = new CancelOrderAction($orderRepository, $eventBus);

        $existingKey = new IdempotencyKey(
            key: 'idem-cancel-1',
            buyerId: new BuyerId('buyer-1'),
            operation: IdempotencyOperation::CANCEL_ORDER,
            requestHash: 'hash-original',
        );

        $idempotencyKeyDataProvider = $this->createMock(IdempotencyKeyDataProviderInterface::class);
        $idempotencyKeyDataProvider->expects(self::once())
            ->method('findByKeyAndBuyerId')
            ->willReturn($existingKey);

        $handler = new CancelOrderHandler(
            $orderDataProvider,
            $cancelOrderAction,
            $idempotencyKeyDataProvider,
        );

        $this->expectException(IdempotencyConflictException::class);
        $handler(new CancelOrderCommand(
            orderId: '00000000-0000-4000-8000-000000000010',
            buyerId: 'buyer-1',
            idempotencyKey: 'idem-cancel-1',
            requestHash: 'hash-different',
        ));
    }
}
