<?php

declare(strict_types=1);

namespace Hibla\MySQL\Exceptions;

use RuntimeException;

/**
 * Exception thrown when transaction-specific operations are called outside a transaction.
 */
class NotInTransactionException extends RuntimeException
{
}
