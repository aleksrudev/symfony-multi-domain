<?php

declare(strict_types=1);

namespace App\Notifications\Infrastructure\Delivery;

use App\Notifications\Application\Command\SendNotification\Provider\NotificationDeliveryChannelInterface;
use App\Notifications\Application\Command\SendNotification\Provider\NotificationDeliveryFailedException;
use App\Notifications\Domain\Entity\Notification;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

#[AsAlias(NotificationDeliveryChannelInterface::class)]
final readonly class LocalMailboxDeliveryChannel implements NotificationDeliveryChannelInterface
{
    public function __construct(
        private bool $failDelivery = false,
    ) {
    }

    public function send(Notification $notification): void
    {
        if ($this->failDelivery) {
            throw new NotificationDeliveryFailedException('Simulated delivery failure via notifications.demo.fail_delivery.');
        }
    }
}
