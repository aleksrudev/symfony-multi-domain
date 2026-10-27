<?php

declare(strict_types=1);

namespace App\Ordering\Application\Command\ReconcileStock;

use App\Ordering\Application\Command\CancelOrder\CancelOrderCommand;
use App\Ordering\Application\Command\ConfirmOrder\ConfirmOrderCommand;
use App\Ordering\Application\Command\FinalizeOrderCancellation\FinalizeOrderCancellationCommand;
use App\Ordering\Application\Command\ReconcileStock\Provider\ReservationResultProviderInterface;
use App\Ordering\Application\Command\RejectOrder\RejectOrderCommand;
use App\Ordering\Domain\DataProvider\OrderDataProviderInterface;
use App\Ordering\Domain\Entity\Order;
use App\Ordering\Domain\Enum\OrderStatus;
use App\Ordering\Domain\Repository\OrderRepositoryInterface;
use App\Shared\Application\Bus\Command\CommandBusInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler(bus: 'command.bus')]
final readonly class ReconcileStockHandler
{
    public function __construct(
        private OrderDataProviderInterface $orderDataProvider,
        private OrderRepositoryInterface $orderRepository,
        private ReservationResultProviderInterface $reservationResultProvider,
        private CommandBusInterface $commandBus,
        private int $checkInterval = 5,
        private int $pendingStockTimeout = 300,
        private int $confirmedTimeout = 900,
    ) {
    }

    public function __invoke(ReconcileStockCommand $command): void
    {
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $orders = $this->orderDataProvider->findOrdersForReconciliation($command->batchSize, $now, $this->confirmedTimeout);

        foreach ($orders as $order) {
            $orderIdStr = $order->id()->value;

            if (OrderStatus::CONFIRMED === $order->status()) {
                if ($order->updatedAt() <= $now->modify("-{$this->confirmedTimeout} seconds")) {
                    $this->commandBus->dispatch(new CancelOrderCommand($orderIdStr));
                }
                continue;
            }

            if (OrderStatus::PENDING_STOCK === $order->status()) {
                if ($order->createdAt() <= $now->modify("-{$this->pendingStockTimeout} seconds")) {
                    $this->commandBus->dispatch(new CancelOrderCommand($orderIdStr));
                    continue;
                }

                $result = $this->reservationResultProvider->getReservationResult($orderIdStr);
                match ($result->status) {
                    'reserved' => $this->commandBus->dispatch(new ConfirmOrderCommand($orderIdStr)),
                    'rejected' => $this->commandBus->dispatch(new RejectOrderCommand($orderIdStr, $result->rejectionReason ?? 'stock_rejected')),
                    'not_found', 'pending' => $this->reschedule($order, $now),
                    default => null,
                };
                continue;
            }

            if (OrderStatus::CANCELLING === $order->status()) {
                $result = $this->reservationResultProvider->getReservationResult($orderIdStr);
                match ($result->status) {
                    'released', 'rejected', 'not_found' => $this->commandBus->dispatch(new FinalizeOrderCancellationCommand($orderIdStr)),
                    'reserved' => $this->reschedule($order, $now),
                    default => null,
                };
            }
        }
    }

    private function reschedule(Order $order, \DateTimeImmutable $now): void
    {
        $nextCheckAt = $now->modify('+'.$this->checkInterval.' seconds');
        $order->rescheduleCheck($nextCheckAt);
        $this->orderRepository->save($order);
    }
}
