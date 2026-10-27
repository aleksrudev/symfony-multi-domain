<?php

declare(strict_types=1);

namespace App\Ordering\Application\Command\ConfirmOrder;

use App\Ordering\Domain\Action\ConfirmOrderAction;
use App\Ordering\Domain\DataProvider\OrderDataProviderInterface;
use App\Ordering\Domain\ValueObject\OrderId;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler(bus: 'command.bus')]
final readonly class ConfirmOrderHandler
{
    public function __construct(
        private OrderDataProviderInterface $orderDataProvider,
        private ConfirmOrderAction $confirmOrderAction,
    ) {
    }

    public function __invoke(ConfirmOrderCommand $command): void
    {
        $order = $this->orderDataProvider->findById(new OrderId($command->orderId), lock: true);

        ($this->confirmOrderAction)($order);
    }
}
