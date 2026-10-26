<?php

declare(strict_types=1);

namespace App\Notifications\Presentation\Http\Error;

use App\Notifications\Domain\Exception\NotificationNotFoundException;
use App\Shared\Presentation\Http\Error\DomainErrorProviderInterface;

final class NotificationsErrorProvider implements DomainErrorProviderInterface
{
    public static function getErrors(): array
    {
        return [
            NotificationNotFoundException::class => [
                'error' => 'notification_not_found',
                'status' => 404,
            ],
        ];
    }
}
