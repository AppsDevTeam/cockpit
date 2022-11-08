<?php

declare(strict_types=1);

namespace ADT\Cockpit;

use Nette\Utils\ArrayHash;

/**
 * Provides objects to work as array.
 * @template T
 */
class Entry extends ArrayHash
{
	/**
	 * Returns a item.
	 * @param  string|int  $key
	 * @return T
	 */
	#[\ReturnTypeWillChange]
	public function offsetGet($key)
	{
		bd ('aaa');
		return $this->$key;
	}
}
