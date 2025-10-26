<?php

declare(strict_types=1);

namespace Hibla\MySQL\Exceptions;

use Throwable;

/**
 * Exception thrown when a transaction fails after all retry attempts.
 */
class TransactionFailedException extends TransactionException
{
    /**
     * @param  string  $message  Error message
     * @param  int  $attempts  Number of attempts made
     * @param  Throwable|null  $previous  Previous exception
     */
    public function __construct(
        string $message,
        private readonly int $attempts = 1,
        ?Throwable $previous = null
    ) {
        parent::__construct($message, 0, $previous);
    }

    /**
     * Gets the number of attempts that were made.
     *
     * @return int
     */
    public function getAttempts(): int
    {
        return $this->attempts;
    }
}
