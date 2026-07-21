<?php

declare(strict_types=1);

namespace ADT\Cockpit;

use ArrayAccess;
use Countable;
use InvalidArgumentException;
use IteratorAggregate;
use RecursiveArrayIterator;

final class Entry implements ArrayAccess, Countable, IteratorAggregate
{
	private array $values;
	private array $onLoad;
	private array $onGetOffset;
	private bool $isInit = false;

	public function __construct(array $values, $onLoad, $onGetOffset)
	{
		foreach ($values as &$value) {
			if (is_array($value)) {
				$this->unsetEmptyCollectionEntries($value);
			}
		}

		$this->values = $values;
		$this->onLoad = $onLoad;
		$this->onGetOffset = $onGetOffset;
	}

	public function getIterator(): RecursiveArrayIterator
	{
		$this->init();

		return new RecursiveArrayIterator($this->values);
	}

	public function count(): int
	{
		$this->init();

		return count($this->values);
	}

	/**
	 * @throws InvalidArgumentException
	 */
	public function offsetSet($offset, $value): void
	{
		if (!is_scalar($offset)) { // prevents null
			throw new InvalidArgumentException(sprintf('Key must be either a string or an integer, %s given.', gettype($offset)));
		}

		$this->values[$offset] = $value;
	}

	public function offsetGet($offset): mixed
	{
		$this->init();

		foreach ($this->onGetOffset as $_callback) {
			$_callback($this->values, $offset);
		}

		return $this->values[$offset] ?? null;
	}

	public function offsetExists($offset): bool
	{
		$this->init();

		return isset($this->values[$offset]);
	}

	public function offsetUnset($offset): void
	{
		unset($this->values[$offset]);
	}

	public function toArray(): array
	{
		return $this->valuesToArray($this->values);
	}

	private function init(): void
	{
		if ($this->isInit) {
			return;
		}

		foreach ($this->onLoad as $_callback) {
			$_callback($this->values);
		}

		$this->toEntry($this->values);

		$this->isInit = true;
	}

	private function valuesToArray(array $values): array
	{
		foreach ($values as &$value) {
			if ($value instanceof self) {
				$value = $value->toArray();
			} elseif (is_array($value)) {
				$value = $this->valuesToArray($value);
			}
		}

		return $values;
	}

	private function toEntry(&$values)
	{
		foreach ($values as &$value) {
			if (is_array($value)) {
				// skip if file
				if (isset($value['path']) && isset($value['mime'])) {
					continue;
				}

				// collection
				if (count(array_filter(array_keys($value), 'is_string')) === 0) {
					$this->toEntry($value);
				}

				// entry
				$value = new Entry($value, $this->onLoad, $this->onGetOffset);
			}
		}
	}

	private function unsetEmptyCollectionEntries(array &$collection)
	{
		// not a collection, but an entry
		if (count(array_filter(array_keys($collection), 'is_string')) > 0) {
			return;
		}

		foreach ($collection as $index => &$_entry)
		{
			// not a collection, but a list
			if (!is_array($_entry)) {
				break;
			}

			$unset = true;
			foreach ($_entry as $key => &$value) {
				if (is_array($value)) {
					$this->unsetEmptyCollectionEntries($value);

					if (empty($value)) {
						unset($_entry[$key]);
						continue;
					}
				}

				if (!str_starts_with($key, '_')) {
					$unset = false;
				}
			}
			if ($unset) {
				unset($collection[$index]);
			}
		}
	}
}
