<?php

declare(strict_types=1);

namespace Hibla\Mysql\Internals;

use Hibla\Mysql\Interfaces\MysqlResult;
use Hibla\Mysql\ValueObjects\MysqlColumnDefinition;
use Rcalicdan\MySQLBinaryProtocol\Frame\Result\ColumnDefinition;

/**
 * Unified result object for all query types.
 *
 * @internal This must not be used directly.
 */
class Result implements MysqlResult
{
    /**
     * The number of rows returned by SELECT queries.
     */
    public readonly int $rowCount;

    /**
     * The number of columns in the result set.
     */
    public readonly int $columnCount;

    /**
     * The full metadata for every column in the result set.
     *
     * @var array<int, MysqlColumnDefinition>
     */
    public readonly array $fields;

    /**
     * The column names from the result set.
     *
     * @var array<int, string>
     */
    public array $columns {
        get => array_map(fn (MysqlColumnDefinition $col) => $col->name, $this->fields);
    }

    private int $position = 0;

    private ?MysqlResult $nextResult = null;

    public function __construct(
        public readonly int $affectedRows = 0,
        public readonly int $lastInsertId = 0,
        public readonly int $warningCount = 0,
        public readonly int $connectionId = 0,
        private readonly array $columnDefinitions = [],
        private readonly array $rows = [],
    ) {
        $this->rowCount = \count($this->rows);
        $this->columnCount = \count($this->columnDefinitions);

        $this->fields = array_map(
            fn (ColumnDefinition $col) => new MysqlColumnDefinition($col),
            $this->columnDefinitions
        );
    }

    /**
     * @internal
     * Links the next result set to this one.
     */
    public function setNextResult(MysqlResult $result): void
    {
        $this->nextResult = $result;
    }

    /**
     * Returns the next result set if it exists.
     */
    public function nextResult(): ?MysqlResult
    {
        return $this->nextResult;
    }

    /**
     * Checks if any rows were affected by the operation.
     */
    public function hasAffectedRows(): bool
    {
        return $this->affectedRows > 0;
    }

    /**
     * Checks if an auto-increment ID was generated.
     */
    public function hasLastInsertId(): bool
    {
        return $this->lastInsertId > 0;
    }

    /**
     * Checks if the result set is empty.
     */
    public function isEmpty(): bool
    {
        return $this->rowCount === 0;
    }

    /**
     * Fetches the next row as an associative array.
     *
     * @return array<string, mixed>|null
     */
    public function fetchAssoc(): ?array
    {
        if ($this->position >= $this->rowCount) {
            return null;
        }

        /** @var array<string, mixed> */
        return $this->rows[$this->position++];
    }

    /**
     * Fetches all rows.
     *
     * @return array<int, array<string, mixed>>
     */
    public function fetchAll(): array
    {
        return $this->rows;
    }

    /**
     * Fetches a single column from all rows.
     *
     * @return array<int, mixed>
     */
    public function fetchColumn(string|int $column = 0): array
    {
        return array_map(fn ($row) => $row[$column] ?? null, $this->rows);
    }

    /**
     * Fetches the first row.
     *
     * @return array<string, mixed>|null
     */
    public function fetchOne(): ?array
    {
        return $this->rows[0] ?? null;
    }

    /**
     * Allows iteration in foreach loops.
     */
    public function getIterator(): \Traversable
    {
        return new \ArrayIterator($this->rows);
    }
}
