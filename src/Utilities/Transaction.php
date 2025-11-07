<?php

declare(strict_types=1);

namespace Hibla\MySQL\Utilities;

use Hibla\MySQL\Exceptions\QueryException;
use Hibla\MySQL\Manager\TransactionManager;
use mysqli;
use mysqli_result;
use mysqli_stmt;

/**
 * Represents an active database transaction with scoped query methods.
 * 
 * This class provides a clean API for executing queries within a transaction context.
 * All queries executed through this object are automatically part of the transaction.
 */
final class Transaction
{
    /**
     * Creates a new Transaction instance.
     *
     * @param mysqli $mysqli The mysqli connection for this transaction
     * @param QueryExecutor $queryExecutor The query executor instance
     * @param TransactionManager $transactionManager The transaction manager instance
     */
    public function __construct(
        private readonly mysqli $mysqli,
        //@phpstan-ignore-next-line
        private readonly QueryExecutor $queryExecutor,
        private readonly TransactionManager $transactionManager
    ) {}

    /**
     * Executes a SELECT query and returns all matching rows.
     *
     * @param  string  $sql  SQL query with optional parameter placeholders (?)
     * @param  array<int, mixed>  $params  Parameter values for prepared statement
     * @param  string|null  $types  Parameter type string (i=integer, d=double, s=string, b=blob). Auto-detected if null.
     * @return array<int, array<string, mixed>> Array of associative arrays
     * 
     * @throws QueryException If query execution fails
     */
    public function query(string $sql, array $params = [], ?string $types = null): array
    {
        $result = $this->prepareAndExecute($sql, $params, $types);

        if ($result instanceof mysqli_result) {
            /** @var array<int, array<string, mixed>> */
            return $result->fetch_all(MYSQLI_ASSOC);
        }

        return [];
    }

    /**
     * Executes a SELECT query and returns the first matching row.
     *
     * @param  string  $sql  SQL query with optional parameter placeholders (?)
     * @param  array<int, mixed>  $params  Parameter values for prepared statement
     * @param  string|null  $types  Parameter type string (i=integer, d=double, s=string, b=blob). Auto-detected if null.
     * @return array<string, mixed>|null Associative array or null if no rows
     * 
     * @throws QueryException If query execution fails
     */
    public function fetchOne(string $sql, array $params = [], ?string $types = null): ?array
    {
        $result = $this->prepareAndExecute($sql, $params, $types);

        if ($result instanceof mysqli_result) {
            $row = $result->fetch_assoc();
            if ($row === null || $row === false) {
                return null;
            }

            return $row;
        }

        return null;
    }

    /**
     * Executes an INSERT, UPDATE, or DELETE statement and returns affected row count.
     *
     * @param  string  $sql  SQL statement with optional parameter placeholders (?)
     * @param  array<int, mixed>  $params  Parameter values for prepared statement
     * @param  string|null  $types  Parameter type string (i=integer, d=double, s=string, b=blob). Auto-detected if null.
     * @return int Number of affected rows
     * 
     * @throws QueryException If query execution fails
     */
    public function execute(string $sql, array $params = [], ?string $types = null): int
    {
        $result = $this->prepareAndExecute($sql, $params, $types);

        if ($result instanceof mysqli_stmt) {
            $affectedRows = $result->affected_rows;
            return $affectedRows < 0 ? 0 : (int)$affectedRows;
        }

        $mysqli = $this->getConnection();
        $affectedRows = $mysqli->affected_rows;
        return $affectedRows < 0 ? 0 : (int)$affectedRows;
    }

    /**
     * Executes a query and returns a single column value from the first row.
     *
     * @param  string  $sql  SQL query with optional parameter placeholders (?)
     * @param  array<int, mixed>  $params  Parameter values for prepared statement
     * @param  string|null  $types  Parameter type string (i=integer, d=double, s=string, b=blob). Auto-detected if null.
     * @return mixed Scalar value or null if no rows
     * 
     * @throws QueryException If query execution fails
     */
    public function fetchValue(string $sql, array $params = [], ?string $types = null): mixed
    {
        $result = $this->prepareAndExecute($sql, $params, $types);

        if ($result instanceof mysqli_result) {
            $row = $result->fetch_row();
            return $row !== null ? $row[0] : null;
        }

        return null;
    }

    /**
     * Prepares and executes a query with optional parameters.
     *
     * @param  string  $sql  SQL query/statement with optional parameter placeholders (?)
     * @param  array<int, mixed>  $params  Parameter values for prepared statement
     * @param  string|null  $types  Parameter type string (i=integer, d=double, s=string, b=blob). Auto-detected if null.
     * @return mysqli_result|mysqli_stmt|bool Query result, statement, or boolean for non-SELECT queries
     * 
     * @throws QueryException If query execution fails
     */
    private function prepareAndExecute(string $sql, array $params = [], ?string $types = null): mysqli_result|mysqli_stmt|bool
    {
        $mysqli = $this->getConnection();

        if (count($params) === 0) {
            return $this->executeSimpleQuery($mysqli, $sql, $params);
        }

        $stmt = $this->prepareStatement($mysqli, $sql, $params);
        $this->bindAndExecuteStatement($stmt, $sql, $params, $types);

        return $this->isSelectQuery($sql)
            ? $this->getStatementResult($stmt, $sql, $params)
            : $stmt;
    }

    /**
     * Executes a simple query without parameters.
     *
     * @param  mysqli  $mysqli  The mysqli connection
     * @param  string  $sql  SQL query
     * @param  array<int, mixed>  $params  Parameters (for error context)
     * @return mysqli_result|bool Query result
     * 
     * @throws QueryException If query execution fails
     */
    private function executeSimpleQuery(mysqli $mysqli, string $sql, array $params): mysqli_result|bool
    {
        $result = $mysqli->query($sql);

        if ($result === false) {
            throw new QueryException('Query failed: ' . $mysqli->error, $sql, $params);
        }

        return $result;
    }

    /**
     * Prepares a SQL statement.
     *
     * @param  mysqli  $mysqli  The mysqli connection
     * @param  string  $sql  SQL query
     * @param  array<int, mixed>  $params  Parameters (for error context)
     * @return mysqli_stmt Prepared statement
     * 
     * @throws QueryException If preparation fails
     */
    private function prepareStatement(mysqli $mysqli, string $sql, array $params): mysqli_stmt
    {
        $stmt = $mysqli->prepare($sql);

        if ($stmt === false) {
            throw new QueryException('Prepare failed: ' . $mysqli->error, $sql, $params);
        }

        return $stmt;
    }

    /**
     * Binds parameters and executes a prepared statement.
     *
     * @param  mysqli_stmt  $stmt  The prepared statement
     * @param  string  $sql  SQL query (for error context)
     * @param  array<int, mixed>  $params  Parameter values
     * @param  string|null  $types  Parameter type string
     * @return void
     * 
     * @throws QueryException If binding or execution fails
     */
    private function bindAndExecuteStatement(mysqli_stmt $stmt, string $sql, array $params, ?string $types): void
    {
        $types ??= ParameterTypes::detect($params);

        if ($types === '') {
            $types = str_repeat('s', count($params));
        }

        $processedParams = ParameterTypes::preprocess($params);

        if ($stmt->bind_param($types, ...$processedParams) === false) {
            throw new QueryException('Bind param failed: ' . $stmt->error, $sql, $params);
        }

        if ($stmt->execute() === false) {
            throw new QueryException('Execute failed: ' . $stmt->error, $sql, $params);
        }
    }

    /**
     * Checks if the SQL query is a SELECT-type query.
     *
     * @param  string  $sql  SQL query
     * @return bool True if SELECT, SHOW, or DESCRIBE query
     */
    private function isSelectQuery(string $sql): bool
    {
        $trimmedSql = ltrim($sql);
        return stripos($trimmedSql, 'SELECT') === 0
            || stripos($trimmedSql, 'SHOW') === 0
            || stripos($trimmedSql, 'DESCRIBE') === 0;
    }

    /**
     * Gets the result from a prepared statement.
     *
     * @param  mysqli_stmt  $stmt  The executed statement
     * @param  string  $sql  SQL query (for error context)
     * @param  array<int, mixed>  $params  Parameters (for error context)
     * @return mysqli_result The query result
     * 
     * @throws QueryException If getting result fails
     */
    private function getStatementResult(mysqli_stmt $stmt, string $sql, array $params): mysqli_result
    {
        $result = $stmt->get_result();

        if ($result === false) {
            throw new QueryException('Get result failed: ' . $stmt->error, $sql, $params);
        }

        return $result;
    }

    /**
     * Registers a callback to execute when this transaction commits.
     *
     * The callback will be executed after the transaction successfully commits
     * but before the transaction() method returns.
     *
     * @param  callable(): void  $callback  Callback to execute on commit
     * @return void
     */
    public function onCommit(callable $callback): void
    {
        $this->transactionManager->onCommit($callback);
    }

    /**
     * Registers a callback to execute when this transaction rolls back.
     *
     * The callback will be executed after the transaction is rolled back
     * but before the exception is re-thrown.
     *
     * @param  callable(): void  $callback  Callback to execute on rollback
     * @return void
     */
    public function onRollback(callable $callback): void
    {
        $this->transactionManager->onRollback($callback);
    }

    /**
     * Gets the underlying mysqli connection.
     * 
     * Useful for advanced operations or raw mysqli access within the transaction.
     *
     * @return mysqli The mysqli connection
     */
    public function getConnection(): mysqli
    {
        return $this->mysqli;
    }
}
