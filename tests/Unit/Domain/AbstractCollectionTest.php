<?php

declare(strict_types=1);

namespace Tests\Unit\Domain;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Fixtures\Collection\Item;
use Tests\Fixtures\Collection\ItemCollection;

final class AbstractCollectionTest extends TestCase
{
    public function testPreservesKeysAndExposesValuesAsAList(): void
    {
        $first = new Item('first');
        $second = new Item('second');
        $collection = new ItemCollection(['first' => $first, 10 => $second]);

        self::assertCount(2, $collection);
        self::assertSame(['first' => $first, 10 => $second], iterator_to_array($collection));
        self::assertSame([$first, $second], $collection->all());
    }

    #[DataProvider('invalidElements')]
    public function testRejectsInvalidElementsThroughEveryInsertionPath(string $operation, mixed $invalid): void
    {
        $original = new Item('original');
        $collection = new ItemCollection([$original]);
        $this->expectException(\TypeError::class);

        try {
            match ($operation) {
                'constructor' => new ItemCollection([$invalid]),
                'add' => $collection->add($invalid),
                'set' => $collection->set('invalid', $invalid),
                'append' => $collection[] = $invalid,
                'offset' => $collection['invalid'] = $invalid,
                'map' => $collection->map(static fn (Item $item): mixed => $invalid),
                default => throw new \LogicException('Unknown insertion path.'),
            };
        } finally {
            self::assertSame([$original], $collection->all());
        }
    }

    public function testAcceptsValidInsertionsAndReplacements(): void
    {
        $first = new Item('first');
        $second = new Item('second');
        $third = new Item('third');
        $replacement = new Item('replacement');
        $collection = new ItemCollection();

        $collection->add($first);
        $collection->set('second', $second);
        $collection[] = $third;
        $collection['second'] = $replacement;

        self::assertSame([0 => $first, 'second' => $replacement, 1 => $third], $collection->toArray());
    }

    /** @return iterable<string, array{string, mixed}> */
    public static function invalidElements(): iterable
    {
        foreach (['constructor', 'add', 'set', 'append', 'offset', 'map'] as $operation) {
            foreach (['null' => null, 'scalar' => 42, 'foreign-object' => new \stdClass()] as $kind => $value) {
                yield $operation.'-'.$kind => [$operation, $value];
            }
        }
    }

    public function testDerivedCollectionsPreserveTheirTypeAndKeys(): void
    {
        $first = new Item('first');
        $second = new Item('second');
        $collection = new ItemCollection(['a' => $first, 'b' => $second]);

        $filtered = $collection->filter(static fn (Item $item): bool => 'second' === $item->name);
        self::assertInstanceOf(ItemCollection::class, $filtered);
        self::assertSame(['b' => $second], $filtered->toArray());

        [$matches, $others] = $collection->partition(static fn (int|string $key, Item $item): bool => 'first' === $item->name);
        self::assertInstanceOf(ItemCollection::class, $matches);
        self::assertInstanceOf(ItemCollection::class, $others);
        self::assertSame(['a' => $first], $matches->toArray());
        self::assertSame(['b' => $second], $others->toArray());

        $mapped = $collection->map(static fn (Item $item): Item => new Item(strtoupper($item->name)));
        self::assertInstanceOf(ItemCollection::class, $mapped);
        self::assertSame(['FIRST', 'SECOND'], array_map(static fn (Item $item): string => $item->name, $mapped->all()));
    }
}
