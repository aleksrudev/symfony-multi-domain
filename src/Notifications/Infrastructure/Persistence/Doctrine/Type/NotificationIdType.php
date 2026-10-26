<?php

declare(strict_types=1);

namespace App\Notifications\Infrastructure\Persistence\Doctrine\Type;

use App\Notifications\Domain\ValueObject\NotificationId;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Types\StringType;

final class NotificationIdType extends StringType
{
    public const NAME = 'notification_id';

    public function convertToDatabaseValue($value, AbstractPlatform $platform): ?string
    {
        if ($value instanceof NotificationId) {
            return $value->value;
        }

        if (is_string($value)) {
            return new NotificationId($value)->value;
        }

        return null;
    }

    public function convertToPHPValue($value, AbstractPlatform $platform): ?NotificationId
    {
        if ($value instanceof NotificationId || null === $value) {
            return $value;
        }

        return new NotificationId($value);
    }

    public function getName(): string
    {
        return self::NAME;
    }
}
