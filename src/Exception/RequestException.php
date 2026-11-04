<?php

declare(strict_types=1);

namespace ADT\Cockpit\Exception;

use Throwable;

/**
 * Cockpit rejected the request (HTTP 4xx): wrong API key, missing permission of the key's role,
 * unknown model, invalid filter. Retrying does not help, so it is never retried
 * and never answered from the fallback storage.
 */
class RequestException extends CockpitException
{
	public function __construct(
		string $message,
		private int $statusCode,
		?Throwable $previous = null,
	) {
		parent::__construct($message, $statusCode, $previous);
	}


	public function getStatusCode(): int
	{
		return $this->statusCode;
	}
}
