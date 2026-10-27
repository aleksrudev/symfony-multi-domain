<?php

declare(strict_types=1);

namespace App\Notifications\Domain\Repository;

use App\Notifications\Domain\Entity\Notification;

interface NotificationRepositoryInterface
{
    public function save(Notification $notification): void;
}
