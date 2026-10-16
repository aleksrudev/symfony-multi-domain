<?php

declare(strict_types=1);

namespace App\Shared\Domain\Collection;

use Doctrine\Common\Collections\ArrayCollection;

/**
 * @template T of object
 *
 * @extends ArrayCollection<array-key, T>
 */
abstract class AbstractCollection extends ArrayCollection
{
    /** @param array<array-key, T> $elements */
    final public function __construct(array $elements = [])
    {
        foreach ($elements as $element) {
            $this->assertElement($element);
        }

        parent::__construct($elements);
    }

    /** @param T $element */
    #[\Override]
    final public function add(mixed $element): void
    {
        $this->assertElement($element);
        parent::add($element);
    }

    /** @param T $value */
    #[\Override]
    final public function set(string|int $key, mixed $value): void
    {
        $this->assertElement($value);
        parent::set($key, $value);
    }

    /** @return list<T> */
    final public function all(): array
    {
        return $this->getValues();
    }

    /** @return class-string<T> */
    abstract protected function elementClass(): string;

    /** @phpstan-assert T $element */
    private function assertElement(mixed $element): void
    {
        $class = $this->elementClass();
        if (!$element instanceof $class) {
            throw new \TypeError(sprintf('%s accepts only %s, %s given.', static::class, $class, get_debug_type($element)));
        }
    }
}
