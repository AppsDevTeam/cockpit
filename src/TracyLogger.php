<?php

declare(strict_types=1);

namespace ADT\Cockpit;

use ADT\Cockpit\Exception\RequestException;
use Throwable;
use Tracy\Debugger;
use Tracy\ILogger;

/**
 * Callback for Cockpit::setOnError() logging through Tracy.
 *
 * - HTTP 4xx (wrong key, missing permission, unknown model) is an error of code or configuration:
 *   logged as an exception, so in production mode it is e-mailed.
 * - Unavailability (connection, timeout, 5xx) is an operational state, it fixes itself and is
 *   answered from the fallback: one line into log/<channel>.log, no e-mail and no HTML dump.
 *
 * NEON: setOnError([ADT\Cockpit\TracyLogger()])
 */
final class TracyLogger
{
	public function __construct(
		private string $channel = 'cockpit',
	) {
	}


	public function __invoke(Throwable $e, string $url, bool $servedFromFallback): void
	{
		if ($e instanceof RequestException) {
			Debugger::log($e, ILogger::EXCEPTION);

			return;
		}

		Debugger::log($e->getMessage() . ($servedFromFallback ? ' [served from fallback]' : ''), $this->channel);
	}
}
