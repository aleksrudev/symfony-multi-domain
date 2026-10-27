<?php

declare(strict_types=1);

namespace App\Notifications\Domain\DataProvider;

use App\Notifications\Domain\Collection\NotificationCollection;
use App\Notifications\Domain\Entity\Notification;
use App\Notifications\Domain\Enum\NotificationType;
use App\Notifications\Domain\ValueObject\NotificationId;

interface NotificationDataProviderInterface
{
    public function findById(NotificationId $id, bool $lock = false): Notification;

    public function findByOrderIdAndType(string $orderId, NotificationType $type): ?Notification;

    public function findByOrderId(string $orderId): NotificationCollection;

    public function findAllPaginated(int $page, int $limit): NotificationCollection;

    public function countAll(): int;
}
