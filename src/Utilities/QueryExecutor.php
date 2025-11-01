<?php

declare(strict_types=1);

namespace Hibla\MySQL\Utilities;

use Hibla\Async\Timer;
use Hibla\MySQL\Exceptions\QueryException;
use Hibla\Promise\Interfaces\PromiseInterface;
use mysqli;
use mysqli_result;
use mysqli_stmt;
use Throwable;

use function Hibla\async;
use function Hibla\await;

/**
 * Handles asynchronous query execution and result processing.
 * 
 * This class manages the complete lifecycle of MySQL query execution including
 * query preparation, execution, async completion waiting, and result processing.
 */
final class QueryExecutor
{
    /** @var int Poll interval in microseconds */
    private const POLL_INTERVAL = 10;

    /** @var int Maximum poll interval in microseconds */
    private const POLL_MAX_INTERVAL = 100;

    /**
     * Executes an async query with the specified result processing type.
     *
     * This method handles the complete lifecycle of query execution including
     * query preparation, execution, result waiting, and result processing.
     *
     * @param  mysqli  $mysqli  MySQL connection
     * @param  string  $sql  SQL query/statement
     * @param  array<int, mixed>  $params  Query parameters
     * @param  string|null  $types  Parameter type string
     * @param  string  $resultType  Type of result processing ('fetchAll', 'fetchOne', 'execute', 'fetchValue')
     * @return PromiseInterface<mixed> Promise resolving to processed result
     *
     * @throws QueryException If query execution fails
     */
    public function executeQuery(
        mysqli $mysqli,
        string $sql,
        array $params,
        ?string $types,
        string $resultType
    ): PromiseInterface {
        return async(function () use ($mysqli, $sql, $params, $types, $resultType) {
            try {
                if (count($params) > 0) {
                    return await($this->executeWithParameters($mysqli, $sql, $params, $types, $resultType));
                }

                return await($this->executeWithoutParameters($mysqli, $sql, $params, $resultType));
            } catch (QueryException $e) {
                throw $e;
            } catch (\mysqli_sql_exception $e) {
                throw new QueryException(
                    'Query execution failed: ' . $e->getMessage(),
                    $sql,
                    $params,
                    $e
                );
            } catch (Throwable $e) {
                throw new QueryException(
                    'Unexpected error during query execution: ' . $e->getMessage(),
                    $sql,
                    $params,
                    $e
                );
            }
        });
    }

    /**
     * Executes a query with parameters using prepared statements.
     *
     * @param  mysqli  $mysqli  MySQL connection
     * @param  string  $sql  SQL query/statement
     * @param  array<int, mixed>  $params  Query parameters
     * @param  string|null  $types  Parameter type string
     * @param  string  $resultType  Type of result processing
     * @return PromiseInterface<mixed> Promise resolving to processed result
     *
     * @throws QueryException If query execution fails
     */
    private function executeWithParameters(
        mysqli $mysqli,
        string $sql,
        array $params,
        ?string $types,
        string $resultType
    ): PromiseInterface {
        return async(function () use ($mysqli, $sql, $params, $types, $resultType) {
            $stmt = $mysqli->prepare($sql);
            if ($stmt === false) {
                throw new QueryException(
                    'Prepare failed: ' . $mysqli->error,
                    $sql,
                    $params
                );
            }

            if ($types === null) {
                $types = $this->detectParameterTypes($params);
            }

            if ($types === '') {
                $types = str_repeat('s', count($params));
            }

            $processedParams = $this->preprocessParameters($params);

            if ($stmt->bind_param($types, ...$processedParams) === false) {
                throw new QueryException(
                    'Bind param failed: ' . $stmt->error,
                    $sql,
                    $params
                );
            }

            if ($stmt->execute() === false) {
                throw new QueryException(
                    'Execute failed: ' . $stmt->error,
                    $sql,
                    $params
                );
            }

            $result = $this->getStatementResult($sql, $stmt);

            return $this->processResult($result, $resultType, $stmt, $mysqli, $sql, $params);
        });
    }

    /**
     * Executes a query without parameters.
     *
     * @param  mysqli  $mysqli  MySQL connection
     * @param  string  $sql  SQL query/statement
     * @param  array<int, mixed>  $params  Query parameters (empty)
     * @param  string  $resultType  Type of result processing
     * @return PromiseInterface<mixed> Promise resolving to processed result
     *
     * @throws QueryException If query execution fails
     */
    private function executeWithoutParameters(
        mysqli $mysqli,
        string $sql,
        array $params,
        string $resultType
    ): PromiseInterface {
        return async(function () use ($mysqli, $sql, $params, $resultType) {
            if ($mysqli->query($sql, MYSQLI_ASYNC) === false) {
                throw new QueryException(
                    'Query failed: ' . $mysqli->error,
                    $sql,
                    $params
                );
            }

            try {
                $result = await($this->waitForAsyncCompletion($mysqli));
            } catch (\mysqli_sql_exception $e) {
                throw new QueryException(
                    'Query execution failed: ' . $e->getMessage(),
                    $sql,
                    $params
                );
            }

            return $this->processResult($result, $resultType, null, $mysqli, $sql, $params);
        });
    }

    /**
     * Gets the result from a prepared statement based on query type.
     *
     * @param  string  $sql  SQL query
     * @param  mysqli_stmt  $stmt  Prepared statement
     * @return mysqli_result|bool Query result
     */
    private function getStatementResult(string $sql, mysqli_stmt $stmt): mysqli_result|bool
    {
        $trimmedSql = trim($sql);

        if (
            stripos($trimmedSql, 'SELECT') === 0 ||
            stripos($trimmedSql, 'SHOW') === 0 ||
            stripos($trimmedSql, 'DESCRIBE') === 0
        ) {
            return $stmt->get_result();
        }

        return true;
    }

    /**
     * Waits for an async query to complete using non-blocking polling.
     *
     * This method polls the connection status using mysqli_poll until
     * the query completes. This provides efficient non-blocking behavior
     * without busy-waiting.
     *
     * @param  mysqli  $mysqli  MySQL connection
     * @return PromiseInterface<mysqli_result> Promise resolving to query result
     *
     * @throws \mysqli_sql_exception If query execution fails
     * @throws QueryException If polling fails
     */
    private function waitForAsyncCompletion(mysqli $mysqli): PromiseInterface
    {
        return async(function () use ($mysqli) {
            $links = [$mysqli];
            $errors = [$mysqli];
            $reject = [$mysqli];

            $ready = mysqli_poll($links, $errors, $reject, 0, 0);

            if ($ready > 0) {
                $result = $mysqli->reap_async_query();
                if ($result === false) {
                    throw new QueryException('Failed to retrieve async query result');
                }
                return $result;
            }

            if ($ready === false) {
                throw new QueryException('MySQLi poll failed immediately');
            }

            $pollInterval = self::POLL_INTERVAL;

            while (true) {
                $links = [$mysqli];
                $errors = [$mysqli];
                $reject = [$mysqli];

                $ready = mysqli_poll($links, $errors, $reject, 0, $pollInterval);

                if ($ready === false) {
                    throw new QueryException('MySQLi poll failed during wait');
                }

                if ($ready > 0) {
                    $result = $mysqli->reap_async_query();
                    if ($result === false) {
                        throw new QueryException('Failed to retrieve async query result');
                    }
                    return $result;
                }

                await(Timer::delay(0));
                $pollInterval = (int) min($pollInterval * 1.2, self::POLL_MAX_INTERVAL);
            }
        });
    }

    /**
     * Processes a query result based on the specified result type.
     *
     * This method converts the raw MySQL result into the appropriate
     * PHP data structure based on the requested result type.
     *
     * @param  mysqli_result|bool  $result  MySQL query result
     * @param  string  $resultType  Type of result processing
     * @param  mysqli_stmt|null  $stmt  Optional prepared statement for error reporting
     * @param  mysqli|null  $mysqli  MySQL connection for error reporting
     * @param  string  $sql  The SQL query for error context
     * @param  array<int, mixed>  $params  The query parameters for error context
     * @return mixed Processed result based on result type
     *
     * @throws QueryException If result is false or processing fails
     */
    private function processResult(
        mysqli_result|bool $result,
        string $resultType,
        ?mysqli_stmt $stmt,
        ?mysqli $mysqli,
        string $sql,
        array $params
    ): mixed {
        if ($result === false) {
            $error = 'Unknown error';
            if ($stmt !== null) {
                $error = $stmt->error;
            } elseif ($mysqli !== null) {
                $error = $mysqli->error;
            }

            throw new QueryException(
                'Query execution failed: ' . $error,
                $sql,
                $params
            );
        }

        return match ($resultType) {
            'fetchAll' => $this->handleFetchAll($result),
            'fetchOne' => $this->handleFetchOne($result),
            'fetchValue' => $this->handleFetchValue($result),
            'execute' => $this->handleExecute($stmt, $mysqli),
            default => $result,
        };
    }

    /**
     * Fetches all rows from a query result.
     *
     * Converts the MySQL result into an array of associative arrays,
     * where each array represents a row with column names as keys.
     *
     * @param  mysqli_result|bool  $result  MySQL query result
     * @return array<int, array<string, mixed>> Array of associative arrays
     */
    private function handleFetchAll(mysqli_result|bool $result): array
    {
        if ($result instanceof mysqli_result) {
            /** @var array<int, array<string, mixed>> */
            return $result->fetch_all(MYSQLI_ASSOC);
        }

        return [];
    }

    /**
     * Fetches the first row from a query result.
     *
     * Converts the first row of the MySQL result into an associative array
     * with column names as keys. Returns null if no rows exist.
     *
     * @param  mysqli_result|bool  $result  MySQL query result
     * @return array<string, float|int|string|null>|null Associative array or null if no rows
     */
    private function handleFetchOne(mysqli_result|bool $result): ?array
    {
        if ($result instanceof mysqli_result) {
            $row = $result->fetch_assoc();

            if ($row === false) {
                return null;
            }

            return $row;
        }

        return null;
    }

    /**
     * Fetches a single column value from the first row.
     *
     * Extracts the first column of the first row from the result set.
     * Useful for aggregate queries like COUNT, SUM, MAX, etc.
     *
     * @param  mysqli_result|bool  $result  MySQL query result
     * @return mixed Scalar value or null if no rows
     */
    private function handleFetchValue(mysqli_result|bool $result): mixed
    {
        if (! ($result instanceof mysqli_result)) {
            return null;
        }

        $row = $result->fetch_row();

        return $row !== null ? $row[0] : null;
    }

    /**
     * Gets the number of affected rows from a query result.
     *
     * Returns the count of rows affected by an INSERT, UPDATE, or DELETE statement.
     *
     * @param  mysqli_stmt|null  $stmt  Optional prepared statement
     * @param  mysqli|null  $mysqli  MySQL connection
     * @return int Number of affected rows
     */
    private function handleExecute(?mysqli_stmt $stmt, ?mysqli $mysqli): int
    {
        if ($stmt !== null) {
            $affectedRows = $stmt->affected_rows;
            return $affectedRows < 0 ? 0 : (int)$affectedRows;
        }
        if ($mysqli !== null) {
            $affectedRows = $mysqli->affected_rows;
            return $affectedRows < 0 ? 0 : (int)$affectedRows;
        }

        return 0;
    }

    /**
     * Detects parameter types from array values.
     *
     * Automatically determines the appropriate type string for mysqli_stmt::bind_param
     * based on the PHP types of the parameter values.
     *
     * @param  array<int, mixed>  $params  Parameter values
     * @return string Type string (i=integer, d=double, s=string, b=blob)
     */
    private function detectParameterTypes(array $params): string
    {
        $types = '';

        foreach ($params as $param) {
            $types .= match (true) {
                $param === null => 's',
                is_bool($param) => 'i',
                is_int($param) => 'i',
                is_float($param) => 'd',
                is_resource($param) => 'b',
                is_string($param) && str_contains($param, "\0") => 'b',
                is_string($param) => 's',
                is_array($param) => 's',
                is_object($param) => 's',
                default => 's',
            };
        }

        return $types;
    }

    /**
     * Preprocesses parameters for binding to prepared statement.
     *
     * Converts PHP values to appropriate types for MySQL binding,
     * including JSON encoding for arrays and objects.
     *
     * @param  array<int, mixed>  $params  Raw parameter values
     * @return array<int, mixed> Processed parameter values
     */
    private function preprocessParameters(array $params): array
    {
        $processedParams = [];

        foreach ($params as $param) {
            $processedParams[] = match (true) {
                $param === null => null,
                is_bool($param) => $param ? 1 : 0,
                is_int($param) || is_float($param) => $param,
                is_resource($param) => $param,
                is_string($param) => $param,
                is_array($param) => json_encode($param),
                is_object($param) && method_exists($param, '__toString') => (string) $param,
                is_object($param) => json_encode($param),
                default => $param,
            };
        }

        return $processedParams;
    }
}
