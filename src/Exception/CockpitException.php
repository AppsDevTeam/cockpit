<?php

declare(strict_types=1);

namespace ADT\Cockpit\Exception;

use GuzzleHttp\Exception\GuzzleException;
use RuntimeException;

/**
 * Base exception of the library.
 *
 * Implements GuzzleException, so `catch (GuzzleException $e)` written for the 1.x library
 * or for project-local clients keeps catching everything the library throws.
 */
class CockpitException extends RuntimeException implements GuzzleException
{
}
