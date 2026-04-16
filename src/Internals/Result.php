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
     * {@inheritDoc}
     */
    public readonly int $rowCount;

    /**
     * {@inheritDoc}
     */
    public readonly int $columnCount;

    /**
     * The full metadata for every column in the result set.
     *
     * @var array<int, MysqlColumnDefinition>
     */
    public readonly array $fields;

    /**
     * {@inheritDoc}
     */
    public array $columns {
        get => array_map(fn (MysqlColumnDefinition $col) => $col->name, $this->fields);
    }

    private int $position = 0;

    private ?MysqlResult $nextResult = null;

    /**
     * @param int $affectedRows Get the affected row that was modified by UPDATE, DELETE, INSERT statement etc.
     * @param int $lastInsertId Get the last inserted ID.
     * @param int $warningCount Get the warning count.
     * @param int $connectionId Get the connection ID.
     * @param array<int, ColumnDefinition> $columnDefinitions
     * @param array<int, array<string, mixed>> $rows
     */
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

        /** @var array<int, MysqlColumnDefinition> $fields */
        $fields = array_map(
            fn (ColumnDefinition $col) => new MysqlColumnDefinition($col),
            $this->columnDefinitions
        );

        $this->fields = $fields;
    }

    /**
     * @internal
     *
     * Links the next result set to this one.
     */
    public function setNextResult(MysqlResult $result): void
    {
        $this->nextResult = $result;
    }

    /**
     * {@inheritDoc}
     */
    public function nextResult(): ?MysqlResult
    {
        return $this->nextResult;
    }

    /**
     * {@inheritDoc}
     */
    public function hasAffectedRows(): bool
    {
        return $this->affectedRows > 0;
    }

    /**
     * {@inheritDoc}
     */
    public function hasLastInsertId(): bool
    {
        return $this->lastInsertId > 0;
    }

    /**
     * {@inheritDoc}
     */
    public function isEmpty(): bool
    {
        return $this->rowCount === 0;
    }

    /**
     * {@inheritDoc}
     */
    public function fetchAssoc(): ?array
    {
        if ($this->position >= $this->rowCount) {
            return null;
        }

        /** @var array<string, mixed> $row */
        $row = $this->rows[$this->position++];

        return $row;
    }

    /**
     * {@inheritDoc}
     */
    public function fetchAll(): array
    {
        return $this->rows;
    }

    /**
     * {@inheritDoc}
     */
    public function fetchColumn(string|int $column = 0): array
    {
        return array_map(fn (array $row) => $row[$column] ?? null, $this->rows);
    }

    /**
     * {@inheritDoc}
     */
    public function fetchOne(): ?array
    {
        /** @var array<string, mixed>|null $row */
        $row = $this->rows[0] ?? null;

        return $row;
    }

    /**
     * {@inheritDoc}
     */
    public function getIterator(): \Traversable
    {
        return new \ArrayIterator($this->rows);
    }
}
