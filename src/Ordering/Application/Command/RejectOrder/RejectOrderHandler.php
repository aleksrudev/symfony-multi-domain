<?php

declare(strict_types=1);

namespace App\Ordering\Application\Command\RejectOrder;

use App\Ordering\Domain\Action\RejectOrderAction;
use App\Ordering\Domain\DataProvider\OrderDataProviderInterface;
use App\Ordering\Domain\ValueObject\OrderId;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler(bus: 'command.bus')]
final readonly class RejectOrderHandler
{
    public function __construct(
        private OrderDataProviderInterface $orderDataProvider,
        private RejectOrderAction $rejectOrderAction,
    ) {
    }

    public function __invoke(RejectOrderCommand $command): void
    {
        $order = $this->orderDataProvider->findById(new OrderId($command->orderId), lock: true);

        ($this->rejectOrderAction)($order, $command->reason);
    }
}
