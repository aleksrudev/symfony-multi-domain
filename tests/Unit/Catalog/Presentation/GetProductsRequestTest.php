<?php

declare(strict_types=1);

namespace Tests\Unit\Catalog\Presentation;

use App\Catalog\Presentation\Http\Request\GetProductsRequest;
use PHPUnit\Framework\TestCase;

final class GetProductsRequestTest extends TestCase
{
    public function testDefaultValues(): void
    {
        $request = new GetProductsRequest();

        self::assertSame(1, $request->page);
        self::assertSame(10, $request->limit);
    }

    public function testExplicitValues(): void
    {
        $request = new GetProductsRequest(page: 3, limit: 25);

        self::assertSame(3, $request->page);
        self::assertSame(25, $request->limit);
    }
}
