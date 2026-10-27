<?php

declare(strict_types=1);

namespace App\Notifications\Application\Command\SendNotification;

use App\Notifications\Application\Command\SendNotification\Provider\NotificationDeliveryChannelInterface;
use App\Notifications\Application\Command\SendNotification\Provider\NotificationDeliveryFailedException;
use App\Notifications\Domain\Action\MarkNotificationSentAction;
use App\Notifications\Domain\Action\RecordNotificationFailureAction;
use App\Notifications\Domain\DataProvider\NotificationDataProviderInterface;
use App\Notifications\Domain\Enum\NotificationStatus;
use App\Notifications\Domain\ValueObject\NotificationId;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler(bus: 'command.bus')]
final readonly class SendNotificationHandler
{
    public function __construct(
        private NotificationDataProviderInterface $dataProvider,
        private NotificationDeliveryChannelInterface $deliveryChannel,
        private MarkNotificationSentAction $markSentAction,
        private RecordNotificationFailureAction $recordFailureAction,
        private int $maxAttempts = 3,
    ) {
    }

    public function __invoke(SendNotificationCommand $command): void
    {
        $notification = $this->dataProvider->findById(new NotificationId($command->notificationId), lock: true);

        if (NotificationStatus::SENT === $notification->status()) {
            return;
        }

        try {
            $this->deliveryChannel->send($notification);
            ($this->markSentAction)($notification, new \DateTimeImmutable('now', new \DateTimeZone('UTC')));
        } catch (NotificationDeliveryFailedException $e) {
            ($this->recordFailureAction)($notification, $e->getMessage(), $this->maxAttempts);
        }
    }
}
