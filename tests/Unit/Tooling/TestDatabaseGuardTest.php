<?php

declare(strict_types=1);

namespace Tests\Unit\Tooling;

use PHPUnit\Framework\TestCase;

final class TestDatabaseGuardTest extends TestCase
{
    public function testOnlyExplicitDedicatedTestDatabaseIsAccepted(): void
    {
        $guard = require dirname(__DIR__, 3).'/tools/test-database-guard.php';
        self::assertSame('template_test', $guard('test', true, ['driver' => 'pdo_pgsql', 'dbname' => 'template_test']));
        self::assertSame('template_test2', $guard('test', true, ['driver' => 'pdo_pgsql', 'dbname' => 'template_test2'], '2'));
    }

    public function testUnsafeTargetsAreRejected(): void
    {
        $guard = require dirname(__DIR__, 3).'/tools/test-database-guard.php';
        foreach ([['prod', true, ['driver' => 'pdo_pgsql', 'dbname' => 'template_test']], ['test', false, ['driver' => 'pdo_pgsql', 'dbname' => 'template_test']], ['test', true, ['driver' => 'pdo_pgsql', 'dbname' => 'template']], ['test', true, ['driver' => 'pdo_sqlite', 'path' => '/tmp/database']]] as $arguments) {
            try {
                $guard(...$arguments);
                self::fail('Unsafe database preparation was accepted.');
            } catch (\InvalidArgumentException $error) {
                self::assertNotEmpty($error->getMessage());
            }
        }
    }
}
