<?php

declare(strict_types=1);

namespace ADT\Cockpit\Exception;

/**
 * Cockpit could not be reached or failed on its side even after retries:
 * connection error, timeout, HTTP 5xx, or a response that is not JSON (e.g. a proxy error page).
 */
class UnavailableException extends CockpitException
{
}
