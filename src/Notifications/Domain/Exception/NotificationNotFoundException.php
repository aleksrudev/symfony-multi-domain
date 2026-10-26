<?php

declare(strict_types=1);

namespace App\Notifications\Domain\Exception;

use App\Notifications\Domain\ValueObject\NotificationId;
use App\Shared\Domain\Exception\DomainException;

final class NotificationNotFoundException extends DomainException
{
    public function __construct(string|NotificationId $id)
    {
        $value = $id instanceof NotificationId ? $id->value : $id;
        parent::__construct(sprintf('Notification "%s" not found.', $value));
    }
}
