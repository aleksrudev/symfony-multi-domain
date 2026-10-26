<?php

declare(strict_types=1);

namespace App\Ordering\Infrastructure\Integration\Notifications;

use App\Notifications\Presentation\Contracts\Dto\EnqueueNotificationInput;
use App\Notifications\Presentation\Contracts\NotificationsContractInterface;
use App\Ordering\Infrastructure\Messaging\Outbox\OrderNotification\OrderNotificationMessage;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler(bus: 'event.bus')]
final readonly class OrderNotificationDeliveryHandler
{
    public function __construct(
        private NotificationsContractInterface $notificationsContract,
    ) {
    }

    public function __invoke(OrderNotificationMessage $message): void
    {
        $this->notificationsContract->enqueueNotification(new EnqueueNotificationInput(
            orderId: $message->orderId,
            type: $message->type,
            buyerId: $message->buyerId,
            payload: $message->payload,
        ));
    }
}
