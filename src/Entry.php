<?php

declare(strict_types=1);

namespace ADT\Cockpit;

use ArrayAccess;
use ArrayIterator;
use Countable;
use InvalidArgumentException;
use IteratorAggregate;
use JsonSerializable;

/**
 * Item of a Cockpit model, accessed like an array; a missing key returns null.
 *
 * Flat (default): nested data (populated links, lists, assets) are plain arrays.
 * Nested: on the first read the onLoad callbacks are called and nested arrays become Entry
 * with the same callbacks, as in the 1.x library; assets (arrays with `path` and `mime`) stay arrays.
 * The onGetOffset callbacks may change the key before every read (e.g. `title` → `title_en`).
 *
 * @implements ArrayAccess<string|int, mixed>
 * @implements IteratorAggregate<string|int, mixed>
 */
class Entry implements ArrayAccess, Countable, IteratorAggregate, JsonSerializable
{
	private bool $isInit = false;


	/**
	 * @param array<string|int, mixed> $values
	 * @param list<callable(array<string|int, mixed>&): void> $onLoad
	 * @param list<callable(array<string|int, mixed>, string|int&): void> $onGetOffset
	 */
	public function __construct(
		private array $values,
		private bool $nested = false,
		private array $onLoad = [],
		private array $onGetOffset = [],
	) {
		if ($nested) {
			foreach ($this->values as &$value) {
				if (is_array($value)) {
					self::unsetEmptyCollectionEntries($value);
				}
			}
		}
	}


	public function offsetExists(mixed $offset): bool
	{
		return isset($this->values[$offset]);
	}


	public function offsetGet(mixed $offset): mixed
	{
		$this->init();

		foreach ($this->onGetOffset as $callback) {
			$callback($this->values, $offset);
		}

		return $this->values[$offset] ?? null;
	}


	public function offsetSet(mixed $offset, mixed $value): void
	{
		if (!is_string($offset) && !is_int($offset)) {
			throw new InvalidArgumentException(sprintf('Key must be either a string or an integer, %s given.', get_debug_type($offset)));
		}

		$this->values[$offset] = $value;
	}


	public function offsetUnset(mixed $offset): void
	{
		unset($this->values[$offset]);
	}


	public function count(): int
	{
		return count($this->values);
	}


	public function getIterator(): ArrayIterator
	{
		$this->init();

		return new ArrayIterator($this->values);
	}


	/**
	 * Values as plain arrays, nested Entry included.
	 * @return array<string|int, mixed>
	 */
	public function toArray(): array
	{
		return array_map(fn($value) => $value instanceof self ? $value->toArray() : $value, $this->values);
	}


	/**
	 * @return array<string|int, mixed>
	 */
	public function jsonSerialize(): array
	{
		return $this->toArray();
	}


	private function init(): void
	{
		if ($this->isInit) {
			return;
		}

		$this->isInit = true;

		foreach ($this->onLoad as $callback) {
			$callback($this->values);
		}

		if ($this->nested) {
			$this->toEntry($this->values);
		}
	}


	/**
	 * @param array<string|int, mixed> $values
	 */
	private function toEntry(array &$values): void
	{
		foreach ($values as &$value) {
			if (!is_array($value) || (isset($value['path']) && isset($value['mime']))) {
				continue;
			}

			// list: its items become Entry first
			if (count(array_filter(array_keys($value), 'is_string')) === 0) {
				$this->toEntry($value);
			}

			$value = new self($value, true, $this->onLoad, $this->onGetOffset);
		}
	}


	/**
	 * Removes items of nested lists that have only system keys (`_id`, `_model`, …), i.e. links
	 * that were not populated, as the 1.x library did.
	 * @param array<string|int, mixed> $collection
	 */
	private static function unsetEmptyCollectionEntries(array &$collection): void
	{
		// not a list, but an item
		if (count(array_filter(array_keys($collection), 'is_string')) > 0) {
			return;
		}

		foreach ($collection as $index => &$entry) {
			// a list of scalars
			if (!is_array($entry)) {
				break;
			}

			$unset = true;
			foreach ($entry as $key => &$value) {
				if (is_array($value)) {
					self::unsetEmptyCollectionEntries($value);

					if (!$value) {
						unset($entry[$key]);
						continue;
					}
				}

				if (!str_starts_with((string) $key, '_')) {
					$unset = false;
				}
			}

			if ($unset) {
				unset($collection[$index]);
			}
		}
	}
}
