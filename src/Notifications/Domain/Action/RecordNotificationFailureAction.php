<?php

declare(strict_types=1);

namespace App\Notifications\Domain\Action;

use App\Notifications\Domain\Entity\Notification;
use App\Notifications\Domain\Repository\NotificationRepositoryInterface;

final readonly class RecordNotificationFailureAction
{
    public function __construct(
        private NotificationRepositoryInterface $repository,
    ) {
    }

    public function __invoke(Notification $notification, string $errorMessage, int $maxAttempts = 3): void
    {
        $notification->recordFailure($errorMessage, $maxAttempts);
        $this->repository->save($notification);
    }
}
