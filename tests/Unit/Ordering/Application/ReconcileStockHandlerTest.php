<?php

declare(strict_types=1);

namespace Tests\Unit\Ordering\Application;

use App\Ordering\Application\Command\ConfirmOrder\ConfirmOrderCommand;
use App\Ordering\Application\Command\ReconcileStock\Provider\ReservationResult;
use App\Ordering\Application\Command\ReconcileStock\Provider\ReservationResultProviderInterface;
use App\Ordering\Application\Command\ReconcileStock\ReconcileStockCommand;
use App\Ordering\Application\Command\ReconcileStock\ReconcileStockHandler;
use App\Ordering\Application\Command\RejectOrder\RejectOrderCommand;
use App\Ordering\Domain\Collection\OrderCollection;
use App\Ordering\Domain\DataProvider\OrderDataProviderInterface;
use App\Ordering\Domain\Entity\Order;
use App\Ordering\Domain\Enum\OrderStatus;
use App\Ordering\Domain\Repository\OrderRepositoryInterface;
use App\Ordering\Domain\ValueObject\BuyerId;
use App\Ordering\Domain\ValueObject\OrderId;
use App\Shared\Application\Bus\Command\CommandBusInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ReconcileStockHandler::class)]
final class ReconcileStockHandlerTest extends TestCase
{
    public function testReconcileStockDispatchesConfirmWhenReserved(): void
    {
        $order = new Order(
            new OrderId('00000000-0000-4000-8000-000000000001'),
            new BuyerId('buyer-1'),
            OrderStatus::PENDING_STOCK,
        );

        $provider = $this->createMock(OrderDataProviderInterface::class);
        $provider->expects(self::once())
            ->method('findPendingStockForReconciliation')
            ->willReturn(new OrderCollection([$order]));

        $repo = $this->createMock(OrderRepositoryInterface::class);

        $resultProvider = $this->createMock(ReservationResultProviderInterface::class);
        $resultProvider->expects(self::once())
            ->method('getReservationResult')
            ->with('00000000-0000-4000-8000-000000000001')
            ->willReturn(new ReservationResult('00000000-0000-4000-8000-000000000001', 'reserved', null));

        $commandBus = $this->createMock(CommandBusInterface::class);
        $commandBus->expects(self::once())
            ->method('dispatch')
            ->with(self::callback(static fn (object $cmd): bool => $cmd instanceof ConfirmOrderCommand && '00000000-0000-4000-8000-000000000001' === $cmd->orderId));

        $handler = new ReconcileStockHandler($provider, $repo, $resultProvider, $commandBus, 5);
        $handler(new ReconcileStockCommand(10));
    }

    public function testReconcileStockDispatchesRejectWhenRejected(): void
    {
        $order = new Order(
            new OrderId('00000000-0000-4000-8000-000000000002'),
            new BuyerId('buyer-1'),
            OrderStatus::PENDING_STOCK,
        );

        $provider = $this->createMock(OrderDataProviderInterface::class);
        $provider->expects(self::once())
            ->method('findPendingStockForReconciliation')
            ->willReturn(new OrderCollection([$order]));

        $repo = $this->createMock(OrderRepositoryInterface::class);

        $resultProvider = $this->createMock(ReservationResultProviderInterface::class);
        $resultProvider->expects(self::once())
            ->method('getReservationResult')
            ->with('00000000-0000-4000-8000-000000000002')
            ->willReturn(new ReservationResult('00000000-0000-4000-8000-000000000002', 'rejected', 'insufficient_stock'));

        $commandBus = $this->createMock(CommandBusInterface::class);
        $commandBus->expects(self::once())
            ->method('dispatch')
            ->with(self::callback(static fn (object $cmd): bool => $cmd instanceof RejectOrderCommand
                && '00000000-0000-4000-8000-000000000002' === $cmd->orderId
                && 'insufficient_stock' === $cmd->reason));

        $handler = new ReconcileStockHandler($provider, $repo, $resultProvider, $commandBus, 5);
        $handler(new ReconcileStockCommand(10));
    }

    public function testReconcileStockReschedulesWhenPendingOrNotFound(): void
    {
        $order = new Order(
            new OrderId('00000000-0000-4000-8000-000000000003'),
            new BuyerId('buyer-1'),
            OrderStatus::PENDING_STOCK,
        );

        $provider = $this->createMock(OrderDataProviderInterface::class);
        $provider->expects(self::once())
            ->method('findPendingStockForReconciliation')
            ->willReturn(new OrderCollection([$order]));

        $repo = $this->createMock(OrderRepositoryInterface::class);
        $repo->expects(self::once())
            ->method('save')
            ->with(self::callback(static function (Order $savedOrder): bool {
                return null !== $savedOrder->nextCheckAt()
                    && OrderStatus::PENDING_STOCK === $savedOrder->status();
            }));

        $resultProvider = $this->createMock(ReservationResultProviderInterface::class);
        $resultProvider->expects(self::once())
            ->method('getReservationResult')
            ->with('00000000-0000-4000-8000-000000000003')
            ->willReturn(new ReservationResult('00000000-0000-4000-8000-000000000003', 'pending', null));

        $commandBus = $this->createMock(CommandBusInterface::class);
        $commandBus->expects(self::never())->method('dispatch');

        $handler = new ReconcileStockHandler($provider, $repo, $resultProvider, $commandBus, 5);
        $handler(new ReconcileStockCommand(10));
    }
}
