<?php

declare(strict_types=1);

namespace Tests\Unit\Architecture;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class DependencyGraphTest extends TestCase
{
    public function testMutualEventDependenciesAreAllowed(): void
    {
        $configuration = $this->build([
            'Alpha' => ['sync' => [], 'events' => ['Beta']],
            'Beta' => ['sync' => [], 'events' => ['Alpha']],
        ]);

        self::assertArrayHasKey('deptrac', $configuration);
    }

    public function testMixedGraphIsAllowed(): void
    {
        $configuration = $this->build([
            'Alpha' => ['sync' => ['Beta'], 'events' => []],
            'Beta' => ['sync' => [], 'events' => ['Alpha']],
        ]);

        self::assertArrayHasKey('deptrac', $configuration);
    }

    public function testSynchronousCycleIsRejected(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('Cyclic synchronous module dependency');
        $this->build([
            'Alpha' => ['sync' => ['Beta'], 'events' => []],
            'Beta' => ['sync' => ['Alpha'], 'events' => []],
        ]);
    }

    public function testFlatFormatIsRejected(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('sync and events');
        $this->build(['Alpha' => ['Beta']]);
    }

    /** @param array<string, mixed> $dependencies */
    #[DataProvider('invalidDeclarations')]
    public function testInvalidDependencyDeclarationsAreRejected(array $dependencies, string $message): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage($message);
        $this->build($dependencies);
    }

    /** @return iterable<string, array{array<string, mixed>, string}> */
    public static function invalidDeclarations(): iterable
    {
        yield 'missing sync' => [['Alpha' => ['events' => ['Beta']]], 'sync and events'];
        yield 'missing events' => [['Alpha' => ['sync' => ['Beta']]], 'sync and events'];
        yield 'unknown channel' => [['Alpha' => ['sync' => [], 'events' => [], 'tasks' => []]], 'channels'];
        yield 'non-array declaration' => [['Alpha' => null], 'sync and events'];
        yield 'sync not a list' => [['Alpha' => ['sync' => ['provider' => 'Beta'], 'events' => []]], 'list'];
        yield 'events not an array' => [['Alpha' => ['sync' => [], 'events' => 'Beta']], 'list'];
        yield 'non-string provider' => [['Alpha' => ['sync' => [42], 'events' => []]], 'provider'];
        yield 'unknown sync provider' => [['Alpha' => ['sync' => ['Missing'], 'events' => []]], 'provider'];
        yield 'unknown event provider' => [['Alpha' => ['sync' => [], 'events' => ['Missing']]], 'provider'];
        yield 'self sync' => [['Alpha' => ['sync' => ['Alpha'], 'events' => []]], 'provider'];
        yield 'self events' => [['Alpha' => ['sync' => [], 'events' => ['Alpha']]], 'provider'];
        yield 'Shared provider' => [['Alpha' => ['sync' => [], 'events' => ['Shared']]], 'provider'];
        yield 'unknown consumer' => [['Missing' => ['sync' => [], 'events' => []]], 'consumer'];
        yield 'Shared consumer' => [['Shared' => ['sync' => [], 'events' => []]], 'consumer'];
    }

    /**
     * @param array<string, mixed> $dependencies
     *
     * @return array<string, mixed>
     */
    private function build(array $dependencies): array
    {
        $root = dirname(__DIR__, 3);
        $build = require $root.'/tools/architecture.php';

        return $build($root.'/tests/Fixtures/Architecture/Allowed', $dependencies);
    }
}
