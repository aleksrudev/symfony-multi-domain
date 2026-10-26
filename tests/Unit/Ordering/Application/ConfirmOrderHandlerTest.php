<?php

declare(strict_types=1);

namespace Tests\Unit\Ordering\Application;

use App\Ordering\Application\Command\ConfirmOrder\ConfirmOrderCommand;
use App\Ordering\Application\Command\ConfirmOrder\ConfirmOrderHandler;
use App\Ordering\Domain\Action\ConfirmOrderAction;
use App\Ordering\Domain\DataProvider\OrderDataProviderInterface;
use App\Ordering\Domain\Entity\Order;
use App\Ordering\Domain\Enum\OrderStatus;
use App\Ordering\Domain\Repository\OrderRepositoryInterface;
use App\Ordering\Domain\ValueObject\BuyerId;
use App\Ordering\Domain\ValueObject\OrderId;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ConfirmOrderHandler::class)]
final class ConfirmOrderHandlerTest extends TestCase
{
    public function testConfirmOrderHandlerLocksAndExecutesAction(): void
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

        $action = new ConfirmOrderAction($orderRepo);
        $handler = new ConfirmOrderHandler($provider, $action);
        $handler(new ConfirmOrderCommand($orderIdStr));

        self::assertSame(OrderStatus::CONFIRMED, $order->status());
    }
}
