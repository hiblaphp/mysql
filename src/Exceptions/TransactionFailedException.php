<?php

declare(strict_types=1);

namespace Hibla\MySQL\Exceptions;

use RuntimeException;
use Throwable;

/**
 * Exception thrown when a transaction fails after all retry attempts.
 */
final class TransactionFailedException extends RuntimeException
{
    /** @var list<array{attempt: int, error: string, time: float}> */
    private array $attemptHistory;

    /**
     * Creates a new TransactionFailedException.
     *
     * @param  string  $message  Exception message
     * @param  int  $attempts  Number of attempts made
     * @param  Throwable|null  $previous  Previous exception that caused the failure
     * @param  list<array{attempt: int, error: string, time: float}>  $attemptHistory  History of all attempts
     */
    public function __construct(
        string $message,
        private readonly int $attempts,
        ?Throwable $previous = null,
        array $attemptHistory = []
    ) {
        parent::__construct($message, 0, $previous);
        $this->attemptHistory = $attemptHistory;
    }

    /**
     * Gets the number of attempts made before failure.
     *
     * @return int Number of attempts
     */
    public function getAttempts(): int
    {
        return $this->attempts;
    }

    /**
     * Gets the history of all transaction attempts.
     *
     * @return list<array{attempt: int, error: string, time: float}>
     */
    public function getAttemptHistory(): array
    {
        return $this->attemptHistory;
    }
}