<?php

declare(strict_types=1);

namespace Tests\Unit\Architecture;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

final class ArchitectureTest extends TestCase
{
    public function testAllowedPortAdapterDependencyPasses(): void
    {
        $process = $this->analyse('Allowed');
        self::assertSame(0, $process->getExitCode(), $process->getOutput().$process->getErrorOutput());
    }

    public function testForeignModelFrameworkAndRepositoryAccessFail(): void
    {
        $process = $this->analyse('Forbidden');
        self::assertSame(1, $process->getExitCode(), $process->getOutput().$process->getErrorOutput());
        foreach (['ForeignModel', 'FrameworkLeak', 'RepositoryAccess', 'SharedLeak'] as $class) {
            self::assertStringContainsString($class, $process->getOutput());
        }
    }

    public function testRegistrationAttributesDoNotExposeFrameworkServicesToInnerLayers(): void
    {
        $allowed = $this->analyse('Allowed');
        self::assertSame(0, $allowed->getExitCode(), $allowed->getOutput().$allowed->getErrorOutput());
        $forbidden = $this->analyse('Forbidden');
        self::assertSame(1, $forbidden->getExitCode(), $forbidden->getOutput().$forbidden->getErrorOutput());
        foreach (['AttributeLeak', 'AttributeLeakEvent', 'MessengerBusLeak'] as $class) {
            self::assertStringContainsString($class, $forbidden->getOutput());
        }
    }

    public function testPublicContractBoundariesAreEnforced(): void
    {
        $process = $this->analyse('Forbidden');
        self::assertSame(1, $process->getExitCode(), $process->getOutput().$process->getErrorOutput());
        foreach (['InternalModelLeak', 'ApplicationContractAccess', 'ForeignContractAccess', 'ForeignAdapterAccess'] as $class) {
            self::assertStringContainsString($class, $process->getOutput());
        }
    }

    public function testIntegrationMarkerIsTheOnlySharedPublicContractAllowedForEvents(): void
    {
        $allowed = $this->analyse('Allowed');
        self::assertSame(0, $allowed->getExitCode(), $allowed->getOutput().$allowed->getErrorOutput());
        $forbidden = $this->analyse('Forbidden');
        self::assertSame(1, $forbidden->getExitCode(), $forbidden->getOutput().$forbidden->getErrorOutput());
        foreach (['SharedContractLeakEvent', 'DomainIntegrationEventLeak', 'ApplicationIntegrationEventLeak'] as $class) {
            self::assertStringContainsString($class, $forbidden->getOutput());
        }
    }

    public function testOnlyTheSharedBaseCanDependOnDoctrineCollections(): void
    {
        $process = $this->analyse('Forbidden');
        self::assertSame(1, $process->getExitCode(), $process->getOutput().$process->getErrorOutput());
        foreach (['DirectDoctrineCollection', 'EntityCollectionLeak', 'SharedCollectionLeak', 'OrmCollectionLeak', 'BaseWithDoctrineLeakCollection'] as $class) {
            self::assertStringContainsString($class, $process->getOutput());
        }
    }

    public function testOnlyTheSharedUidBasesCanDependOnSymfonyUid(): void
    {
        $process = $this->analyse('Forbidden');
        self::assertSame(1, $process->getExitCode(), $process->getOutput().$process->getErrorOutput());
        foreach (['DirectUid', 'SharedUidLeak', 'UlidId'] as $class) {
            self::assertStringContainsString($class, $process->getOutput());
        }
    }

    public function testEventsPermissionDoesNotExposeSynchronousInterfaces(): void
    {
        $process = $this->analyse('EventsOnly');

        self::assertSame(1, $process->getExitCode(), $process->getOutput().$process->getErrorOutput());
        self::assertStringContainsString('ForbiddenSynchronousAccess', $process->getOutput());
        self::assertStringNotContainsString('AllowedEventAccess', $process->getOutput());
        self::assertStringNotContainsString('AllowedDtoAccess', $process->getOutput());
    }

    public function testSyncPermissionDoesNotExposePublicEvents(): void
    {
        $process = $this->analyse('SyncOnly');

        self::assertSame(1, $process->getExitCode(), $process->getOutput().$process->getErrorOutput());
        self::assertStringContainsString('ForbiddenEventAccess', $process->getOutput());
        self::assertStringNotContainsString('AllowedSynchronousAccess', $process->getOutput());
        self::assertStringNotContainsString('AllowedDtoAccess', $process->getOutput());
    }

    public function testFlatDependencyDeclarationFailsWithClearError(): void
    {
        $process = $this->analyse('FlatFormat');

        self::assertSame(2, $process->getExitCode());
        self::assertStringContainsString('Alpha', $process->getErrorOutput());
        self::assertStringContainsString('sync and events', $process->getErrorOutput());
        self::assertStringNotContainsString('Stack trace', $process->getErrorOutput());
    }

    private function analyse(string $fixture): Process
    {
        $root = dirname(__DIR__, 3);
        $process = new Process([PHP_BINARY, $root.'/bin/architecture', $root.'/tests/Fixtures/Architecture/'.$fixture]);
        $process->setTimeout(60);
        $process->run();

        return $process;
    }
}
