<?php

declare(strict_types=1);

namespace App\Ordering\Application\Command\FinalizeOrderCancellation;

use App\Ordering\Domain\Action\FinalizeOrderCancellationAction;
use App\Ordering\Domain\DataProvider\OrderDataProviderInterface;
use App\Ordering\Domain\Exception\OrderNotFoundException;
use App\Ordering\Domain\ValueObject\OrderId;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler(bus: 'command.bus')]
final readonly class FinalizeOrderCancellationHandler
{
    public function __construct(
        private OrderDataProviderInterface $orderDataProvider,
        private FinalizeOrderCancellationAction $finalizeOrderCancellationAction,
    ) {
    }

    public function __invoke(FinalizeOrderCancellationCommand $command): void
    {
        try {
            $orderId = new OrderId($command->orderId);
        } catch (\InvalidArgumentException) {
            throw new OrderNotFoundException($command->orderId);
        }

        $order = $this->orderDataProvider->findById($orderId, lock: true);

        ($this->finalizeOrderCancellationAction)($order);
    }
}
