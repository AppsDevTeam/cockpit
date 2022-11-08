<?php

declare(strict_types=1);

namespace ADT\Cockpit;

use ArrayAccess;
use Countable;
use Exception;
use IteratorAggregate;
use RecursiveArrayIterator;

final class Entry implements ArrayAccess, Countable, IteratorAggregate
{
	private array $values;
	private array $onLoad = [];
	private array $onGetOffset = [];

	public function __construct(array $values)
	{
		$this->values = $values;
	}

	public function setOnLoad(array $callbacks): self
	{
		$this->onLoad = $callbacks;
		return $this;
	}

	public function setOnGetOffset(array $callbacks): self
	{
		$this->onGetOffset = $callbacks;
		return $this;
	}

	public function getIterator(): RecursiveArrayIterator
	{
		return new RecursiveArrayIterator($this->values);
	}

	public function count(): int
	{
		return count($this->values);
	}

	/**
	 * @throws Exception
	 */
	public function offsetSet($offset, $value): void
	{
		if (!is_scalar($offset)) { // prevents null
			throw new Exception(sprintf('Key must be either a string or an integer, %s given.', gettype($offset)));
		}

		$this->values[$offset] = $value;
	}

	public function offsetGet($offset)
	{
		foreach ($this->onLoad as $_callback)
		{
			$_callback($this->values);
		}
		$this->onLoad = [];

		foreach ($this->onGetOffset as $_callback) {
			$_callback($this->values, $offset);
		}

		return $this->values[$offset];
	}

	public function offsetExists($offset): bool
	{
		return isset($this->values[$offset]);
	}

	public function offsetUnset($offset): void
	{
		unset($this->values[$offset]);
	}
}
