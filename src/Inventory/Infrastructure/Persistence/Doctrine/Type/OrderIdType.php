<?php

declare(strict_types=1);

namespace App\Inventory\Infrastructure\Persistence\Doctrine\Type;

use App\Inventory\Domain\ValueObject\OrderId;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Types\StringType;

final class OrderIdType extends StringType
{
    public const NAME = 'inventory_order_id';

    public function convertToDatabaseValue($value, AbstractPlatform $platform): ?string
    {
        if ($value instanceof OrderId) {
            return $value->value;
        }

        if (is_string($value)) {
            return new OrderId($value)->value;
        }

        return null;
    }

    public function convertToPHPValue($value, AbstractPlatform $platform): ?OrderId
    {
        if ($value instanceof OrderId || null === $value) {
            return $value;
        }

        return new OrderId($value);
    }

    public function getName(): string
    {
        return self::NAME;
    }
}

