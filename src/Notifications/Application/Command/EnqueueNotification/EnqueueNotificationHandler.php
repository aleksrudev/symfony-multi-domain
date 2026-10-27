<?php

declare(strict_types=1);

namespace App\Notifications\Application\Command\EnqueueNotification;

use App\Notifications\Application\Command\SendNotification\SendNotificationCommand;
use App\Notifications\Domain\Action\CreateNotificationAction;
use App\Notifications\Domain\Enum\NotificationType;
use App\Shared\Application\Bus\Command\CommandBusInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler(bus: 'command.bus')]
final readonly class EnqueueNotificationHandler
{
    public function __construct(
        private CreateNotificationAction $createNotificationAction,
        private CommandBusInterface $commandBus,
    ) {
    }

    public function __invoke(EnqueueNotificationCommand $command): void
    {
        $type = NotificationType::from($command->type);

        $notification = ($this->createNotificationAction)(
            orderId: $command->orderId,
            type: $type,
            buyerId: $command->buyerId,
            payload: $command->payload,
        );

        $this->commandBus->dispatch(new SendNotificationCommand($notification->id()->value));
    }
}
