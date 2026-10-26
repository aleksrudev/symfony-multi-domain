<?php

declare(strict_types=1);

namespace Tests\Unit\Ordering\Application;

use App\Ordering\Application\Command\FinalizeOrderCancellation\FinalizeOrderCancellationCommand;
use App\Ordering\Application\Command\FinalizeOrderCancellation\FinalizeOrderCancellationHandler;
use App\Ordering\Domain\Action\FinalizeOrderCancellationAction;
use App\Ordering\Domain\DataProvider\OrderDataProviderInterface;
use App\Ordering\Domain\Entity\Order;
use App\Ordering\Domain\Enum\OrderStatus;
use App\Ordering\Domain\Exception\OrderNotFoundException;
use App\Ordering\Domain\Repository\OrderRepositoryInterface;
use App\Ordering\Domain\ValueObject\BuyerId;
use App\Ordering\Domain\ValueObject\OrderId;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(FinalizeOrderCancellationHandler::class)]
final class FinalizeOrderCancellationHandlerTest extends TestCase
{
    public function testFinalizeCancellationInvokesAction(): void
    {
        $orderId = new OrderId('00000000-0000-4000-8000-000000000020');
        $order = new Order($orderId, new BuyerId('buyer-1'), OrderStatus::CANCELLING);

        $orderDataProvider = $this->createMock(OrderDataProviderInterface::class);
        $orderDataProvider->expects(self::once())
            ->method('findById')
            ->with(self::callback(static fn (OrderId $id): bool => $id->value === $orderId->value), true)
            ->willReturn($order);

        $orderRepository = $this->createMock(OrderRepositoryInterface::class);
        $orderRepository->expects(self::once())
            ->method('save')
            ->with($order);

        $action = new FinalizeOrderCancellationAction($orderRepository);
        $handler = new FinalizeOrderCancellationHandler($orderDataProvider, $action);

        $handler(new FinalizeOrderCancellationCommand($orderId->value));

        self::assertSame(OrderStatus::CANCELLED, $order->status());
    }

    public function testFinalizeCancellationThrowsOnInvalidUuid(): void
    {
        $orderDataProvider = $this->createMock(OrderDataProviderInterface::class);
        $orderRepository = $this->createMock(OrderRepositoryInterface::class);
        $action = new FinalizeOrderCancellationAction($orderRepository);
        $handler = new FinalizeOrderCancellationHandler($orderDataProvider, $action);

        $this->expectException(OrderNotFoundException::class);
        $handler(new FinalizeOrderCancellationCommand('invalid-uuid'));
    }
}
