<?php

declare(strict_types=1);

namespace App\Notifications\Infrastructure\Delivery;

use App\Notifications\Application\Command\SendNotification\Provider\NotificationDeliveryChannelInterface;
use App\Notifications\Application\Command\SendNotification\Provider\NotificationDeliveryFailedException;
use App\Notifications\Domain\Entity\Notification;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

#[AsAlias(NotificationDeliveryChannelInterface::class)]
final class LocalMailboxDeliveryChannel implements NotificationDeliveryChannelInterface
{
    private static ?bool $overrideFailDelivery = null;

    public function __construct(
        private readonly bool $failDelivery = false,
    ) {
    }

    public static function setOverrideFailDelivery(?bool $override): void
    {
        self::$overrideFailDelivery = $override;
    }

    public function send(Notification $notification): void
    {
        if (self::$overrideFailDelivery ?? $this->failDelivery) {
            throw new NotificationDeliveryFailedException('Simulated delivery failure via notifications.demo.fail_delivery.');
        }
    }
}
