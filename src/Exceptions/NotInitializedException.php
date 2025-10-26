<?php

declare(strict_types=1);

namespace Hibla\MySQL\Exceptions;

use RuntimeException;

/**
 * Exception thrown when attempting to use an uninitialized connection.
 */
class NotInitializedException extends RuntimeException
{
}
