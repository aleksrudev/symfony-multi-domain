<?php

declare(strict_types=1);

namespace App\Inventory\Infrastructure\Persistence\Doctrine\Type;

use App\Inventory\Domain\ValueObject\ProductId;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Types\StringType;

final class ProductIdType extends StringType
{
    public const NAME = 'inventory_product_id';

    public function convertToDatabaseValue($value, AbstractPlatform $platform): ?string
    {
        if ($value instanceof ProductId) {
            return $value->value;
        }

        return is_string($value) ? $value : null;
    }

    public function convertToPHPValue($value, AbstractPlatform $platform): ?ProductId
    {
        if ($value instanceof ProductId || null === $value) {
            return $value;
        }

        return new ProductId($value);
    }

    public function getName(): string
    {
        return self::NAME;
    }
}

