<?php

declare(strict_types=1);

namespace Tests\Unit\Catalog\Presentation;

use App\Catalog\Presentation\Http\Request\UpdateProductRequest;
use PHPUnit\Framework\TestCase;

final class UpdateProductRequestTest extends TestCase
{
    public function testDefaultValuesAreNull(): void
    {
        $request = new UpdateProductRequest();

        self::assertNull($request->price);
        self::assertNull($request->published);
    }

    public function testExplicitValues(): void
    {
        $request = new UpdateProductRequest(price: 150000, published: false);

        self::assertSame(150000, $request->price);
        self::assertFalse($request->published);
    }
}

