<?php

declare(strict_types=1);

namespace App\Notifications\Application\Command\SendNotification\Provider;

use App\Notifications\Domain\Entity\Notification;

interface NotificationDeliveryChannelInterface
{
    /**
     * @throws NotificationDeliveryFailedException
     */
    public function send(Notification $notification): void;
}
