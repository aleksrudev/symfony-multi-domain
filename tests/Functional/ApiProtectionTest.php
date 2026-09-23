<?php

declare(strict_types=1);

namespace Tests\Functional;

use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Tests\Fixtures\ModuleKernel;

final class ApiProtectionTest extends TestCase
{
    public function testModuleApiStillRequiresAuthentication(): void
    {
        $kernel = new ModuleKernel('test', true);
        try {
            $response = $kernel->handle(Request::create('/api/v1/probe'));
            self::assertSame(401, $response->getStatusCode());
            self::assertNotSame('{"ok":true}', $response->getContent());
        } finally {
            $kernel->shutdown();
        }
    }
}
