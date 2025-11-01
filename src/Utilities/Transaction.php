<?php

declare(strict_types=1);

namespace Hibla\MySQL\Utilities;

use Hibla\MySQL\Manager\TransactionManager;
use Hibla\Promise\Interfaces\PromiseInterface;
use mysqli;

use function Hibla\await;

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
        private readonly QueryExecutor $queryExecutor,
        private readonly TransactionManager $transactionManager
    ) {}

    /**
     * Executes a SELECT query and returns all matching rows.
     *
     * @param  string  $sql  SQL query with optional parameter placeholders (?)
     * @param  array<int, mixed>  $params  Parameter values for prepared statement
     * @param  string|null  $types  Parameter type string (i=integer, d=double, s=string, b=blob). Auto-detected if null.
     * @return PromiseInterface<array<int, array<string, mixed>>> Promise resolving to array of associative arrays
     */
    public function query(string $sql, array $params = [], ?string $types = null): PromiseInterface
    {
        /** @var PromiseInterface<array<int, array<string, mixed>>> */
        return $this->queryExecutor->executeQuery(
            $this->mysqli,
            $sql,
            $params,
            $types,
            'fetchAll'
        );
    }

    /**
     * Executes a SELECT query and returns the first matching row.
     *
     * @param  string  $sql  SQL query with optional parameter placeholders (?)
     * @param  array<int, mixed>  $params  Parameter values for prepared statement
     * @param  string|null  $types  Parameter type string (i=integer, d=double, s=string, b=blob). Auto-detected if null.
     * @return PromiseInterface<array<string, mixed>|null> Promise resolving to associative array or null if no rows
     */
    public function fetchOne(string $sql, array $params = [], ?string $types = null): PromiseInterface
    {
        /** @var PromiseInterface<array<string, mixed>|null> */
        return $this->queryExecutor->executeQuery(
            $this->mysqli,
            $sql,
            $params,
            $types,
            'fetchOne'
        );
    }

    /**
     * Executes an INSERT, UPDATE, or DELETE statement and returns affected row count.
     *
     * @param  string  $sql  SQL statement with optional parameter placeholders (?)
     * @param  array<int, mixed>  $params  Parameter values for prepared statement
     * @param  string|null  $types  Parameter type string (i=integer, d=double, s=string, b=blob). Auto-detected if null.
     * @return PromiseInterface<int> Promise resolving to number of affected rows
     */
    public function execute(string $sql, array $params = [], ?string $types = null): PromiseInterface
    {
        /** @var PromiseInterface<int> */
        return $this->queryExecutor->executeQuery(
            $this->mysqli,
            $sql,
            $params,
            $types,
            'execute'
        );
    }

    /**
     * Executes a query and returns a single column value from the first row.
     *
     * @param  string  $sql  SQL query with optional parameter placeholders (?)
     * @param  array<int, mixed>  $params  Parameter values for prepared statement
     * @param  string|null  $types  Parameter type string (i=integer, d=double, s=string, b=blob). Auto-detected if null.
     * @return PromiseInterface<mixed> Promise resolving to scalar value or null if no rows
     */
    public function fetchValue(string $sql, array $params = [], ?string $types = null): PromiseInterface
    {
        return $this->queryExecutor->executeQuery(
            $this->mysqli,
            $sql,
            $params,
            $types,
            'fetchValue'
        );
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