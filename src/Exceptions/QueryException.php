<?php

declare(strict_types=1);

namespace Hibla\MySQL\Exceptions;

use RuntimeException;
use Throwable;

/**
 * Exception thrown when a query fails to execute.
 */
class QueryException extends RuntimeException
{
    /**
     * @param  string  $message  Error message
     * @param  string  $sql  The SQL query that failed
     * @param  array<int, mixed>  $params  Query parameters
     * @param  Throwable|null  $previous  Previous exception
     */
    public function __construct(
        string $message,
        private readonly string $sql = '',
        private readonly array $params = [],
        ?Throwable $previous = null
    ) {
        parent::__construct($message, 0, $previous);
    }

    /**
     * Gets the SQL query that caused the exception.
     *
     * @return string
     */
    public function getSql(): string
    {
        return $this->sql;
    }

    /**
     * Gets the parameters that were used with the query.
     *
     * @return array<int, mixed>
     */
    public function getParams(): array
    {
        return $this->params;
    }
}
