<?php

declare(strict_types=1);

namespace App\Notifications\Domain\Collection;

use App\Notifications\Domain\Entity\Notification;
use App\Shared\Domain\Collection\AbstractCollection;

/**
 * @extends AbstractCollection<Notification>
 */
final class NotificationCollection extends AbstractCollection
{
    protected function elementClass(): string
    {
        return Notification::class;
    }
}
