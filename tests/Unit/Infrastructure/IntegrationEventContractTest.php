<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure;

use App\Shared\Presentation\Contracts\Event\IntegrationEventInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;
use Tests\Fixtures\Modules\Alpha\Presentation\Contracts\Dto\ProbeItem;
use Tests\Fixtures\Modules\Alpha\Presentation\Contracts\Event\ProbePublishedEvent;

final class IntegrationEventContractTest extends TestCase
{
    public function testMetadataCanBeReadThroughContractAndSurvivesSerialization(): void
    {
        $event = new ProbePublishedEvent(
            value: 7,
            items: [new ProbeItem('item-1', 3)],
            eventId: 'cf39d9bf-d4f1-4a57-a156-fde22c8ce753',
            eventAt: '2026-10-22T09:30:00.123456Z',
        );
        $snapshot = unserialize(serialize($event), ['allowed_classes' => [ProbePublishedEvent::class, ProbeItem::class]]);

        self::assertInstanceOf(IntegrationEventInterface::class, $snapshot);
        self::assertSame('cf39d9bf-d4f1-4a57-a156-fde22c8ce753', $snapshot->eventId);
        self::assertSame('2026-10-22T09:30:00.123456Z', $snapshot->eventAt);
        self::assertSame('Alpha', $snapshot->source);
        self::assertSame('alpha.published', $snapshot->type);
        self::assertSame(1, $snapshot->schemaVersion);
        self::assertInstanceOf(ProbePublishedEvent::class, $snapshot);
        self::assertSame(7, $snapshot->value);
        self::assertSame('item-1', $snapshot->items[0]->code);
        self::assertSame(3, $snapshot->items[0]->quantity);
    }

    #[DataProvider('invalidMetadataDeclarations')]
    public function testMetadataContractRejectsIncompleteOrIncorrectDto(string $properties): void
    {
        $autoload = var_export(dirname(__DIR__, 3).'/vendor/autoload.php', true);
        $script = 'require '.$autoload.'; final readonly class InvalidEvent implements '
            .'\\'.IntegrationEventInterface::class.' { public function __construct('.$properties.') {} }';
        $process = new Process([PHP_BINARY, '-n', '-d', 'display_errors=stderr', '-r', $script]);
        $process->run();

        self::assertSame(255, $process->getExitCode(), 'The metadata contract accepted an invalid event.');
        self::assertStringContainsString('InvalidEvent', $process->getErrorOutput());
    }

    /** @return iterable<string, array{string}> */
    public static function invalidMetadataDeclarations(): iterable
    {
        $properties = [
            'eventId' => 'public string $eventId',
            'eventAt' => 'public string $eventAt',
            'source' => 'public string $source',
            'type' => 'public string $type',
            'schemaVersion' => 'public int $schemaVersion',
        ];
        foreach (array_keys($properties) as $missing) {
            yield 'missing '.$missing => [implode(', ', array_diff_key($properties, [$missing => true]))];
        }
        yield 'incorrect eventId type' => [implode(', ', ['public int $eventId', ...array_slice($properties, 1)])];
    }
}
