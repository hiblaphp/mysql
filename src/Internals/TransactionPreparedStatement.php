<?php

declare(strict_types=1);

namespace Hibla\Mysql\Internals;

use Hibla\Mysql\Interfaces\MysqlResult;
use Hibla\Mysql\Interfaces\MysqlRowStream;
use Hibla\Promise\Interfaces\PromiseInterface;
use Hibla\Promise\Promise;
use Hibla\Sql\PreparedStatement as PreparedStatementInterface;

/**
 * A wrapper around PreparedStatement used strictly inside Transactions.
 *
 * This class automatically sends COM_STMT_CLOSE to the server when the
 * statement is closed or goes out of scope (garbage collected), preventing
 * server-side memory leaks.
 *
 * Crucially, unlike ManagedPreparedStatement, this DOES NOT release the
 * underlying TCP connection back to the pool, as the Transaction still owns it.
 *
 * @internal
 */
class TransactionPreparedStatement implements PreparedStatementInterface
{
    private bool $isClosed = false;

    public function __construct(
        private readonly PreparedStatementInterface $statement,
        private readonly Connection $connection,
        private readonly ?\Closure $onStreamError = null,
    ) {
    }

    /**
     * {@inheritdoc}
     *
     * @return PromiseInterface<MysqlResult>
     */
    public function execute(array $params = []): PromiseInterface
    {
        /** @var PromiseInterface<MysqlResult> $promise */
        $promise = $this->statement->execute($params);

        return Promise::propagateCancellation($promise);
    }

    /**
     * {@inheritdoc}
     *
     * @return PromiseInterface<MysqlRowStream>
     */
    public function executeStream(array $params = [], int $bufferSize = 100): PromiseInterface
    {
        /** @var PromiseInterface<MysqlRowStream> $promise */
        $promise = $this->statement->executeStream($params, $bufferSize);

        if ($this->onStreamError !== null) {
            $onStreamError = $this->onStreamError;

            $promise = $promise->then(
                function (MysqlRowStream $stream) use ($onStreamError): MysqlRowStream {
                    if ($stream instanceof RowStream) {
                        $stream->onCancel($onStreamError);

                        $cmd = $stream->waitForCommand();
                        if (! $cmd->isSettled()) {
                            $cmd->onCancel($onStreamError);
                            $cmd->catch(static function () use ($onStreamError): void {
                                $onStreamError();
                            });
                        }
                    }

                    return $stream;
                }
            );
        }

        return Promise::propagateCancellation($promise);
    }

    /**
     * {@inheritdoc}
     *
     * @return PromiseInterface<void>
     */
    public function close(): PromiseInterface
    {
        if ($this->isClosed) {
            return Promise::resolved();
        }

        $this->isClosed = true;

        return $this->statement->close();
    }

    /**
     * Destructor ensures the server-side statement is closed when the object
     * goes out of scope.
     */
    public function __destruct()
    {
        if (! $this->isClosed && ! $this->connection->isClosed()) {
            $this->close();
        }
    }
}