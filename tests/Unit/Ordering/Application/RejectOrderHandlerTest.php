<?php

declare(strict_types=1);

namespace Tests\Unit\Ordering\Application;

use App\Ordering\Application\Command\RejectOrder\RejectOrderCommand;
use App\Ordering\Application\Command\RejectOrder\RejectOrderHandler;
use App\Ordering\Domain\Action\RejectOrderAction;
use App\Ordering\Domain\DataProvider\OrderDataProviderInterface;
use App\Ordering\Domain\Entity\Order;
use App\Ordering\Domain\Enum\OrderStatus;
use App\Ordering\Domain\Repository\OrderRepositoryInterface;
use App\Ordering\Domain\ValueObject\BuyerId;
use App\Ordering\Domain\ValueObject\OrderId;
use App\Shared\Domain\Event\EventBusInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(RejectOrderHandler::class)]
final class RejectOrderHandlerTest extends TestCase
{
    public function testRejectOrderHandlerLocksAndExecutesAction(): void
    {
        $orderIdStr = '00000000-0000-4000-8000-000000000123';
        $order = new Order(new OrderId($orderIdStr), new BuyerId('buyer-1'), OrderStatus::PENDING_STOCK);

        $provider = $this->createMock(OrderDataProviderInterface::class);
        $provider->expects(self::once())
            ->method('findById')
            ->with(self::callback(static fn (OrderId $id): bool => $id->value === $orderIdStr), true)
            ->willReturn($order);

        $orderRepo = $this->createMock(OrderRepositoryInterface::class);
        $orderRepo->expects(self::once())
            ->method('save')
            ->with($order);

        $eventBus = $this->createMock(EventBusInterface::class);
        $eventBus->expects(self::once())->method('dispatch');

        $action = new RejectOrderAction($orderRepo, $eventBus);
        $handler = new RejectOrderHandler($provider, $action);
        $handler(new RejectOrderCommand($orderIdStr, 'insufficient_stock'));

        self::assertSame(OrderStatus::REJECTED, $order->status());
        self::assertSame('insufficient_stock', $order->rejectionReason());
    }
}
