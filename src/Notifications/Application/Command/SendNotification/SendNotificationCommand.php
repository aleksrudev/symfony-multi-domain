<?php

declare(strict_types=1);

namespace App\Notifications\Application\Command\SendNotification;

use App\Shared\Application\Bus\Command\CommandInterface;

final readonly class SendNotificationCommand implements CommandInterface
{
    public function __construct(
        public string $notificationId,
    ) {
    }
}
