<?php

declare(strict_types=1);

namespace App\Notifications\Domain\Action;

use App\Notifications\Domain\Entity\Notification;
use App\Notifications\Domain\Repository\NotificationRepositoryInterface;

final readonly class MarkNotificationSentAction
{
    public function __construct(
        private NotificationRepositoryInterface $repository,
    ) {
    }

    public function __invoke(Notification $notification, \DateTimeImmutable $sentAt): void
    {
        $notification->markSent($sentAt);
        $this->repository->save($notification);
    }
}
