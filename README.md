# Hibla MySQL Client
**A modern, async-first, high-performance MySQL client for PHP with robust connection pooling, prepared statements, streaming, and full transaction support written in Pure PHP.**

[![Latest Release](https://img.shields.io/github/release/hiblaphp/mysql.svg?style=flat-square)](https://github.com/hiblaphp/mysql/releases)
[![Total Downloads](https://img.shields.io/packagist/dt/hiblaphp/mysql.svg?style=flat-square)](https://packagist.org/packages/hiblaphp/mysql)
[![MIT License](https://img.shields.io/badge/license-MIT-blue.svg?style=flat-square)](./LICENSE)

---

## Features

| Feature | Status | Notes |
|---|---|---|
| Lazy connection pooling | Supported | No TCP connections opened until the first query |
| Parameterized queries | Supported | Binary protocol via prepared statements; SQL-injection safe |
| Named parameters (`:name`) | Supported | Parsed into positional `?` at the client; works with `query()`, `prepare()`, and all transaction methods |
| Prepared statements | Supported | Explicit lifecycle control with `prepare()` / `close()` |
| Statement caching | Supported | Per-connection LRU cache; eliminates repeated `COM_STMT_PREPARE` round-trips |
| Streaming results | Supported | Row-by-row delivery with backpressure; supports large result sets |
| Transactions | Supported | High-level `transaction()` with auto commit/rollback and retry; low-level `beginTransaction()` |
| Stored procedures | Supported | Multi-result-set chains via `nextResult()` |
| Multi-statements | Supported | Disabled by default; see [security warning](#multi-statements) |
| SSL/TLS | Supported | TLS 1.2/1.3; optional mutual TLS and certificate verification |
| zlib compression | Supported | MySQL protocol compression via `CLIENT_COMPRESS` |
| Server-side query cancellation | Supported | Opt-in `KILL QUERY` via side-channel TCP connection |
| Health checks | Supported | `healthCheck()` pings idle connections; evicts stale ones |
| Pool stats | Supported | `$client->stats` for live pool introspection |
| MariaDB compatibility | Supported | Core protocol fully compatible; some MariaDB-only extensions may not work, see [MariaDB compatibility](#mariadb-compatibility) |
| `hiblaphp/sql` contracts | Supported | Fully implements `SqlClientInterface`; drivers are swappable |
| `LOAD DATA LOCAL INFILE` | Planned | Not yet implemented; throws `QueryException` if attempted |

## Contents

**Getting started**
- [Installation](#installation)
- [Quick start](#quick-start)
- [How it works](#how-it-works)
- [hiblaphp/sql contracts](#hiblaphpsql-contracts)

**Core API**
- [The `MysqlClient`](#the-mysqlclient)
- [Making queries](#making-queries)
  - [Simple queries](#simple-queries-text-protocol)
  - [Queries with parameters](#queries-with-parameters-binary-prepared-statements)
  - [Named parameters](#named-parameters)
  - [Convenience methods](#convenience-methods)
- [Prepared statements](#prepared-statements)
- [Streaming results](#streaming-results)
- [Transactions](#transactions)
  - [High-level API: `transaction()`](#high-level-api-transaction)
  - [Low-level API: `beginTransaction()`](#low-level-api-begintransaction)
  - [Savepoints](#savepoints)
  - [Transaction lifecycle rules](#transaction-lifecycle-rules)
- [Stored procedures](#stored-procedures)
- [Multi-statements](#multi-statements)

**Advanced features**
- [Connection pooling](#connection-pooling)
  - [Check-on-borrow health strategy](#check-on-borrow-health-strategy)
  - [Shutdown strategies](#shutdown-strategies)
  - [`resetConnection` and statement cache interaction](#resetconnection-and-statement-cache-interaction)
- [Health checks & pool stats](#health-checks--pool-stats)
  - [Health check](#health-check)
  - [Pool stats](#pool-stats)
- [Configuration options](#configuration-options)
- [Limitations](#limitations)
- [MariaDB compatibility](#mariadb-compatibility)
- [SSL/TLS](#ssltls)
- [zlib compression](#zlib-compression)
- [Query cancellation](#query-cancellation)
- [onConnect hook](#onconnect-hook)
- [Statement caching](#statement-caching)

**Working with responses**
- [Result inspection](#result-inspection)
- [Numeric type handling](#numeric-type-handling)
- [Multiple result sets](#multiple-result-sets)

**Development**
- [Development](#development)

**Reference**
- [API Reference](#api-reference)
  - [`MysqlClient`](#mysqlclient-1)
  - [`PreparedStatementInterface`](#preparedstatementinterface-managedpreparedstatement)
  - [`TransactionInterface`](#transactioninterface)
- [Exceptions](#exceptions)

**Meta**
- [Credits](#credits)
- [License](#license)

---

## Installation

>This package is currently in **beta**. Before installing, ensure your `composer.json`
allows beta releases:

```json
{
    "minimum-stability": "beta",
    "prefer-stable": true
}
```

```bash
composer require hiblaphp/mysql
```

**Requirements:**
- PHP 8.4+ 

**PHP extensions:**

| Extension | Required | Notes |
|---|---|---|
| `openssl` | Optional | Required for SSL/TLS connections. Must be enabled if `ssl: true` is set in config. If not enabled, the connection will be rejected at handshake time if the extension is unavailable. See [SSL/TLS](#ssltls). |
| `zlib` | Optional | Required for MySQL protocol compression. Must be loaded if `compress: true` is set in config. Included in most standard PHP builds. See [zlib compression](#zlib-compression). |
| `bcmath` | Optional | Required for precise `BIGINT UNSIGNED` handling on 64-bit PHP when the value exceeds `PHP_INT_MAX`, and for all `BIGINT` arithmetic on 32-bit PHP. Also recommended for applications handling `DECIMAL` columns where exact string-based precision is required. See [Numeric type handling](#numeric-type-handling). |

All three extensions are optional at install time but will be needed at runtime if you use the features they support. Most standard PHP builds ship with all three enabled. Run `php -m` to verify which extensions are available in your environment.

---

## Quick start

```php
use Hibla\Mysql\MysqlClient;
use function Hibla\await;

// The client is lazy by default, so no connections are opened until the first query.
$client = new MysqlClient('mysql://test_user:test_password@127.0.0.1/test');

// Simple query
$users = await($client->query('SELECT * FROM users WHERE active = ?', [true]));
echo $users->rowCount; // property, not method (e.g. 42)

// Named parameters
$user = await($client->query(
    'SELECT * FROM users WHERE email = :email AND status = :status',
    ['email' => 'alice@example.com', 'status' => 'active']
));

// Prepared statement (recommended for repeated execution)
$stmt = await($client->prepare('SELECT * FROM users WHERE email = :email'));
$result = await($stmt->execute(['email' => 'alice@example.com']));
$stmt->close();

// Streaming large result sets
$stream = await($client->stream('SELECT * FROM logs ORDER BY id DESC'));
foreach ($stream as $row) {
    processLog($row);
}
```

---

## How it works

`MysqlClient` manages a **lazy connection pool** of asynchronous MySQL connections. By default, `minConnections` is `0`, meaning no TCP connections are opened until the first query actually arrives. Resources are created on demand and returned to the pool for reuse. This makes the client cheap to instantiate and well-suited to environments where database activity is bursty or infrequent.

- All operations return `PromiseInterface` objects.
- You can use `await()` for linear code or `.then()` chaining.
- Parameterized queries use the **MySQL binary protocol** (prepared statements), which is more efficient and SQL-injection safe.
- Both positional `?` and named `:name` placeholders are supported. Named parameters are resolved entirely on the client side before the query is sent.
- Server-side query cancellation via `KILL QUERY` is **opt-in** and disabled by default (see [Query cancellation](#query-cancellation)).
- Implements [`hiblaphp/sql`](#hiblaphpsql-contracts) interfaces, making clients swappable at the type level.

```php
use function Hibla\await;
use Hibla\Promise\Promise;

// Three queries run concurrently. Connections are borrowed from the pool
// (and created on demand) only as each query starts.
[$users, $orders, $stats] = await(Promise::all([
    $client->query('SELECT * FROM users'),
    $client->query('SELECT * FROM orders'),
    $client->query('SELECT COUNT(*) FROM stats'),
]));
```

---

## hiblaphp/sql contracts

`MysqlClient` fully implements the [`hiblaphp/sql`](https://github.com/hiblaphp/sql) contract package, which defines the common interfaces shared across all Hibla database drivers:

| Interface | Implemented by |
|---|---|
| `SqlClientInterface` | `MysqlClient` |
| `QueryInterface` | `MysqlClient`, `Transaction` |
| `StreamingQueryInterface` | `MysqlClient`, `Transaction` |
| `PreparedStatement` | `ManagedPreparedStatement`, `TransactionPreparedStatement` |
| `Transaction` | `Transaction` |
| `Result` | `Result` |
| `RowStream` | `RowStream` |

This means you can type-hint against `SqlClientInterface` or `Transaction` in your application code and swap the underlying driver without changing any business logic:

```php
use Hibla\Sql\SqlClientInterface;
use Hibla\Sql\Transaction;

// Your service depends on the contract, not the MySQL-specific implementation.
class UserRepository
{
    public function __construct(private readonly SqlClientInterface $db) {}
}
```

---

## The `MysqlClient`

```php
use Hibla\Mysql\MysqlClient;

// From DSN string — lazy, no connections opened yet
$client = new MysqlClient('mysql://user:pass@localhost:3306/mydb');

// From array
$client = new MysqlClient([
    'host'     => '127.0.0.1',
    'port'     => 3306,
    'username' => 'test_user',
    'password' => 'test_password',
    'database' => 'test',
    'charset'  => 'utf8mb4',
]);

// With explicit pool settings
$client = new MysqlClient(
    config: 'mysql://...',
    minConnections: 0,
    maxConnections: 20,
    idleTimeout: 300,
    maxLifetime: 3600,
    statementCacheSize: 512,
    enableStatementCache: true,
    maxWaiters: 100,
    acquireTimeout: 10.0,
    enableServerSideCancellation: true,
    resetConnection: true,
    multiStatements: false,
    onConnect: function (ConnectionSetup $setup) {
        await($setup->execute("SET SESSION time_zone = '+00:00'"));
    },
);
```

### Constructor parameters

| Parameter | Type | Default | Description |
|---|---|---|---|
| `$config` | `MysqlConfig\|array\|string` | — | Database configuration. Accepts a DSN string (e.g. `mysql://user:pass@host/db`), an associative array of options, or a `MysqlConfig` object. See [Configuration options](#configuration-options) for all accepted keys. |
| `$minConnections` | `int` | `0` | Minimum number of connections to keep open. Defaults to `0`, meaning the pool is fully lazy and no TCP connections are opened until the first query arrives. Set to a value greater than `0` only if you need pre-warmed connections at startup. |
| `$maxConnections` | `int` | `10` | Hard cap on the number of open connections in the pool. Queries that arrive when all connections are in use will wait up to `$acquireTimeout` seconds for one to become free. |
| `$idleTimeout` | `int` | `60` | Seconds a connection can remain idle in the pool before it is evicted and closed. Lower this if your server or proxy silently drops idle connections before this threshold. |
| `$maxLifetime` | `int` | `3600` | Maximum seconds a connection may live before it is rotated out, regardless of whether it is idle or active. Helps prevent issues with long-lived connections accumulating server-side state. |
| `$statementCacheSize` | `int` | `256` | Maximum number of prepared statements to cache per connection (LRU eviction). Only relevant when `$enableStatementCache` is `true`. |
| `$enableStatementCache` | `bool` | `true` | Whether to cache prepared statements per connection. When enabled, `query($sql, $params)` reuses existing server-side statement handles instead of issuing a new `COM_STMT_PREPARE` on every call. Disable only if you are managing statement lifecycles entirely through explicit `prepare()` calls. |
| `$maxWaiters` | `int` | `0` | Maximum number of callers that may queue waiting for a free connection before a `PoolException` is thrown immediately. `0` means unlimited and callers will always queue and wait up to `$acquireTimeout`. Set a non-zero value to shed load fast under pressure rather than letting the wait queue grow unbounded. |
| `$acquireTimeout` | `float` | `10.0` | Maximum seconds to wait for a free connection before throwing a `PoolException`. Applies per-query when the pool is at capacity. |
| `$enableServerSideCancellation` | `bool\|null` | `null` | Controls whether cancelling a query promise dispatches `KILL QUERY` to the server. `true` enables it, `false` disables it, `null` defers to the value in `$config`. When `null` and `$config` does not specify it, server-side cancellation is disabled. See [Query cancellation](#query-cancellation). |
| `$resetConnection` | `bool\|null` | `null` | Controls whether `COM_RESET_CONNECTION` is sent when a connection is returned to the pool. `true` enables it, `false` disables it, `null` defers to the value in `$config`. Wiping session state on return prevents one caller's session variables from leaking into the next. Note that this also clears all server-side prepared statement handles — the statement cache is cleared automatically on the next borrow. See [Statement caching](#statement-caching). |
| `$multiStatements` | `bool\|null` | `null` | Controls whether multiple SQL statements separated by `;` may be sent in a single call. `true` enables it, `false` disables it, `null` defers to the value in `$config`. **Enabling this is a security risk** because a successful SQL injection can chain arbitrary additional statements in the same round-trip. See [Multi-statements](#multi-statements). |
| `$onConnect` | `callable\|null` | `null` | Optional hook invoked on every new physical connection immediately after the MySQL handshake completes. Receives a `ConnectionSetup` instance exposing `query()` and `execute()`. Use it to set session variables, time zones, or SQL modes. If `$resetConnection` is enabled, this hook is also re-invoked after every reset. See [onConnect hook](#onconnect-hook). |
| `$connector` | `ConnectorInterface\|null` | `null` | Optional custom socket connector. When `null`, the default async TCP connector from `hiblaphp/socket` is used. Supply a custom implementation to add proxy support, custom TLS handling, or connection-level instrumentation. |

---

## Making queries

### Simple queries (text protocol)

```php
$result = await($client->query('SELECT * FROM users LIMIT 10'));
```

### Queries with parameters (binary prepared statements)

When `$params` are provided, the library automatically uses a prepared statement over the binary protocol. The statement is transparently cached per connection by default.

```php
$result = await($client->query(
    'SELECT id, name, email FROM users WHERE created_at > ? AND status = ?',
    [$since, 'active']
));
```

### Named parameters

Named placeholders (`:name` syntax) are supported as an alternative to positional `?`. They are resolved entirely on the client side before the query reaches MySQL, so there is no server-side or driver-level dependency and named parameters work identically across MySQL and MariaDB.

```php
// Named params in query()
$result = await($client->query(
    'SELECT * FROM users WHERE status = :status AND created_at > :since',
    ['status' => 'active', 'since' => $since]
));

// Named params with execute() — order of keys does not matter
$result = await($client->query(
    'INSERT INTO orders (user_id, total, status) VALUES (:userId, :total, :status)',
    ['status' => 'pending', 'total' => 99.99, 'userId' => 42] // any order
));

// Named params via prepare() — most useful when executing the same statement repeatedly
$stmt = await($client->prepare(
    'SELECT * FROM products WHERE category_id = :categoryId AND price > :minPrice'
));

$electronics = await($stmt->execute(['categoryId' => 1, 'minPrice' => 50.00]));
$clothing    = await($stmt->execute(['categoryId' => 2, 'minPrice' => 25.00]));

$stmt->close();
```

**Rules for named parameters:**

- Named and positional `?` placeholders cannot be mixed in the same query.
- Parameter names must start with a letter (`a–z`, `A–Z`) or underscore (`_`) and may contain letters, digits, and underscores.
- Named parameters work identically inside `transaction()`, `beginTransaction()`, and all streaming methods.

### Convenience methods

```php
// Returns affected row count
$count = await($client->execute(
    'UPDATE users SET last_login = NOW() WHERE id = :id',
    ['id' => $userId]
));

// Returns last insert ID
$lastId = await($client->executeGetId(
    'INSERT INTO users (name, email) VALUES (:name, :email)',
    ['name' => 'Alice', 'email' => 'alice@example.com']
));

// Returns first row as associative array, or null
$user = await($client->fetchOne(
    'SELECT * FROM users WHERE id = :id',
    ['id' => $userId]
));

// Returns value of first column (or named column) from first row
$name = await($client->fetchValue(
    'SELECT name FROM users WHERE id = :id',
    ['id' => $userId]
));
```

---

## Prepared statements

Use explicit prepared statements when you need to execute the same query many times and want direct control over the statement lifecycle. Both positional `?` and named `:name` placeholders are supported.

```php
// Positional placeholders
$stmt = await($client->prepare(
    'SELECT * FROM products WHERE category_id = ? AND price > ?'
));
$result1 = await($stmt->execute([1, 50.00]));
$result2 = await($stmt->execute([2, 100.00]));
$stmt->close();

// Named placeholders — order of keys in execute() does not matter
$stmt = await($client->prepare(
    'SELECT * FROM products WHERE category_id = :categoryId AND price > :minPrice'
));
$result1 = await($stmt->execute(['categoryId' => 1, 'minPrice' => 50.00]));
$result2 = await($stmt->execute(['minPrice' => 100.00, 'categoryId' => 2])); // order irrelevant
$stmt->close();
```

`close()` sends `COM_STMT_CLOSE` to the server and is called automatically on destruct if omitted, but explicit calls are strongly recommended.

> **Note:** `MysqlClient::query()` with parameters handles statement preparation and caching for you transparently. Explicit `prepare()` is intended for cases where you hold the statement open yourself across many executions.

---

## Streaming results

Rows are yielded as they arrive from the server with **backpressure support**, so the socket is automatically paused when the internal buffer fills and resumed when it drains.

```php
$stream = await($client->stream(
    'SELECT * FROM large_table ORDER BY id',
    bufferSize: 200
));

// Inspect stream metadata before iterating
echo $stream->columnCount; // int, number of columns
print_r($stream->columns); // array of column names

foreach ($stream as $row) {
    processRow($row);
}
```

You can also stream **prepared statement** results with either positional or named parameters:

```php
// With named parameters
$stmt = await($client->prepare(
    'SELECT * FROM logs WHERE created_at > :since AND level = :level'
));
$stream = await($stmt->executeStream(['since' => $since, 'level' => 'error']));

echo $stream->columnCount;
print_r($stream->columns);

foreach ($stream as $row) {
    processRow($row);
}
```

> **Concurrent use:** If you are consuming a stream alongside other concurrent async work, wrap the `foreach` in `async()` to avoid blocking the event loop while waiting for the next buffer fill:
>
> ```php
> await(async(function () use ($client) {
>     $stream = await($client->stream($sql));
>     foreach ($stream as $row) { ... }
> }));
> ```

---

## Transactions

Transactions use `START TRANSACTION` (not `SET SESSION TRANSACTION ISOLATION LEVEL`), which means **isolation levels are scoped strictly to the individual transaction**. They do not leak into the session or affect any other concurrent query on the same connection. Each transaction starts clean, and the connection is returned to the pool in its original session state when the transaction completes.

---

### High-level API: `transaction()`

The `transaction()` method is the recommended way to run a transaction. It handles `START TRANSACTION`, commit, rollback, and optional retry automatically so you only write the business logic.

**The callback is implicitly wrapped in a `Fiber` via `async()`.** This means `await()` is safe to call freely inside it without blocking the event loop. Concurrent async work, nested queries, and streaming all behave correctly inside the callback with no extra setup required.

```php
use Hibla\Sql\TransactionOptions;
use Hibla\Sql\IsolationLevel;

// Named parameters work identically inside transactions
$result = await($client->transaction(function (TransactionInterface $tx) use ($from, $to) {
    await($tx->execute(
        'UPDATE accounts SET balance = balance - :amount WHERE id = :id',
        ['amount' => 100, 'id' => $from]
    ));
    await($tx->execute(
        'UPDATE accounts SET balance = balance + :amount WHERE id = :id',
        ['amount' => 100, 'id' => $to]
    ));

    return 'Transfer completed';
}));
```

**Partial failure is never silently committed.** If any `await()` inside the callback throws, the client automatically rolls back the entire transaction and re-throws the exception.

**Retry on transient failures** such as deadlocks and lock wait timeouts is built in via `TransactionOptions`. The entire callback is re-run from scratch on each attempt.

```php
await($client->transaction(
    function (TransactionInterface $tx) use ($from, $to) {
        await($tx->execute(
            'UPDATE accounts SET balance = balance - :amount WHERE id = :id',
            ['amount' => 100, 'id' => $from]
        ));
        await($tx->execute(
            'UPDATE accounts SET balance = balance + :amount WHERE id = :id',
            ['amount' => 100, 'id' => $to]
        ));
    },
    TransactionOptions::default()
        ->withAttempts(3)
        ->withIsolationLevel(IsolationLevel::REPEATABLE_READ)
));
```

---

### Low-level API: `beginTransaction()`

Use `beginTransaction()` when you need explicit control over the transaction lifecycle.

```php
$tx = await($client->beginTransaction());
try {
    await($tx->execute(
        'UPDATE accounts SET balance = balance - :amount WHERE id = :id',
        ['amount' => 100, 'id' => $from]
    ));
    await($tx->execute(
        'UPDATE accounts SET balance = balance + :amount WHERE id = :id',
        ['amount' => 100, 'id' => $to]
    ));
    await($tx->commit());
} catch (\Throwable $e) {
    await($tx->rollback());
    throw $e;
}
```

Unlike `transaction()`, the low-level API does **not** retry automatically and does **not** wrap the work in a fiber. Prefer `transaction()` in all cases where it is sufficient.

---

### Savepoints

Savepoints let you mark a point within a transaction and roll back to it selectively without abandoning the entire transaction.

```php
await($client->transaction(function (TransactionInterface $tx) {
    await($tx->execute(
        'INSERT INTO audit_log (event) VALUES (:event)',
        ['event' => 'attempt']
    ));

    await($tx->savepoint('before_risky_op'));

    try {
        await($tx->execute(
            'INSERT INTO external_refs (id) VALUES (:id)',
            ['id' => $externalId]
        ));
    } catch (\Throwable $e) {
        await($tx->rollbackTo('before_risky_op'));
    }

    await($tx->releaseSavepoint('before_risky_op'));
}));
```

Rolling back to a savepoint also **clears the tainted state** on the transaction, so you can continue issuing queries after a partial rollback.

---

### Transaction lifecycle rules

**Isolation level scoping.** Isolation levels are applied via `SET TRANSACTION ISOLATION LEVEL` immediately before `START TRANSACTION`, scoping them strictly to that transaction. The session isolation level is never mutated.

**Tainted state.** If any query inside a transaction throws, the transaction is marked tainted. The client will reject all further queries on that transaction until you call `rollback()` or roll back to a savepoint via `rollbackTo()`.

**Automatic rollback on partial failure.** When using `transaction()`, any unhandled exception from the callback causes an automatic `ROLLBACK` before the exception propagates to the caller.

**GC safety net.** If a `Transaction` object is garbage collected without an explicit `commit()` or `rollback()`, a `ROLLBACK` is issued automatically. Always manage the lifecycle explicitly.

**`commit()` and `rollback()` are not cancellable.** These operations always run to completion regardless of the `enableServerSideCancellation` setting.

---

## Stored procedures

Stored procedures are fully supported. Results are returned as a **linked chain** traversable via `nextResult()`.

```php
$result = await($client->query('CALL get_user_with_orders(?)', [$userId]));

foreach ($result as $row) {
    echo $row['name'];
}

$orders = $result->nextResult();
if ($orders !== null) {
    foreach ($orders as $order) {
        echo $order['total'];
    }
}
```

---

## Multi-statements

> **Security warning — disabled by default.** Multi-statement support allows multiple SQL statements separated by `;` to be sent in a single call. Enabling this significantly increases the blast radius of a SQL injection vulnerability. Only enable it if you have a genuine need and fully understand the risk.

```php
$client = new MysqlClient([
    'host'             => 'localhost',
    'username'         => 'root',
    'password'         => '',
    'database'         => 'app',
    'multi_statements' => true,
]);

$result = await($client->query('SELECT * FROM users; SELECT * FROM orders; SELECT COUNT(*) FROM stats'));

foreach ($result as $row) { ... }             // users
foreach ($result->nextResult() as $row) { ... } // orders
$count = $result->nextResult()->nextResult()->fetchOne();
```

---

## Connection pooling

The pool manages the full connection lifecycle automatically. By default it is **fully lazy** (`minConnections: 0`).

```php
$client = new MysqlClient(
    config: $config,
    minConnections: 0,
    maxConnections: 50,
    idleTimeout: 600,
    maxLifetime: 3600,
    acquireTimeout: 10.0,
    resetConnection: true,
);
```

### Check-on-borrow health strategy

Before a connection is checked out of the pool, the client verifies it is still alive, catching stale connections that were silently dropped by the server, a proxy, or a firewall. A connection that fails the check is discarded and replaced transparently.

### Shutdown strategies

```php
// Graceful — stops new work, waits for active queries to finish, then closes
await($client->closeAsync(timeout: 30.0));

// Force — closes everything immediately, rejects pending waiters
$client->close();
```

The destructor issues a force-close automatically when the object is garbage collected.

### `resetConnection` and statement cache interaction

When `resetConnection` is enabled, `COM_RESET_CONNECTION` wipes all server-side prepared statement handles. The client automatically clears the per-connection statement cache on checkout to prevent executing stale statement IDs. The `onConnect` hook is also **re-run after every reset**.

---

## Health checks & pool stats

### Health check

```php
$result = await($client->healthCheck());
// e.g. ['checked' => 5, 'failed' => 1, 'evicted' => 1]
```

### Pool stats

```php
$stats = $client->stats;
// e.g. ['total' => 8, 'idle' => 5, 'active' => 3, 'waiting' => 0]
```

---

## Configuration options

| Option | Type | Default | Description |
|---|---|---|---|
| `host` | string | — | MySQL server hostname or IP |
| `port` | int | `3306` | TCP port |
| `username` | string | `'root'` | MySQL username |
| `password` | string | `''` | MySQL password |
| `database` | string | `''` | Default schema |
| `charset` | string | `'utf8mb4'` | Connection character set |
| `connect_timeout` | int | `10` | Seconds before a connect attempt is aborted |
| `ssl` | bool | `false` | Require SSL/TLS |
| `ssl_ca` | string\|null | `null` | Path to CA certificate |
| `ssl_cert` | string\|null | `null` | Path to client certificate |
| `ssl_key` | string\|null | `null` | Path to client key |
| `ssl_verify` | bool | `false` | Verify server certificate |
| `compress` | bool | `false` | Enable zlib protocol compression |
| `enable_server_side_cancellation` | bool | `false` | Dispatch `KILL QUERY` on promise cancellation |
| `kill_timeout_seconds` | float | `3.0` | Timeout for the `KILL QUERY` side-channel |
| `reset_connection` | bool | `false` | Send `COM_RESET_CONNECTION` on pool release |
| `multi_statements` | bool | `false` | Allow stacked queries — **security risk** |

---

## Limitations

| Feature | Notes |
|---|---|
| `LOAD DATA LOCAL INFILE` | Not implemented. Attempting to use it will result in a `QueryException`. |

---

## MariaDB compatibility

This client connects to MariaDB using the standard MySQL binary protocol handshake, so all core features work out of the box. The full test suite runs against MariaDB LTS on every push (see `.github/workflows/mariadb.yml`).

| Feature | Status |
|---|---|
| Queries and parameterized queries | ✅ Fully supported |
| Named parameters (`:name`) | ✅ Fully supported |
| Prepared statements | ✅ Fully supported |
| Statement caching | ✅ Fully supported |
| Streaming results | ✅ Fully supported |
| Transactions and savepoints | ✅ Fully supported |
| Stored procedures | ✅ Fully supported |
| SSL/TLS | ✅ Fully supported |
| zlib compression | ✅ Fully supported |
| Connection pooling | ✅ Fully supported |

> **MariaDB-specific extensions:** Some features that go beyond the standard MySQL protocol are not supported and may not work correctly. These include but may not be limited to:
>
> - **`RETURNING` clause** on `INSERT`/`UPDATE`/`DELETE` (MariaDB 10.5+) — the client does not handle result sets returned by DML statements in this form.
> - **Sequence objects** (`CREATE SEQUENCE`, `NEXT VALUE FOR`) — no dedicated handling; you can still query sequence values as plain SQL but `lastInsertId` semantics may not reflect sequence values.
> - **MariaDB-specific JSON functions and syntax** that deviate from MySQL's JSON dialect.
> - **`COMPRESSED` column format** and other storage engine extensions that affect the wire protocol.
>
> If you encounter a MariaDB-specific feature that does not work as expected, please keep it mind that this is a Mysql client connector not a dedicated MariaDb client.

---

## SSL/TLS

```php
// Require SSL with full server certificate verification
$client = new MysqlClient([
    'host'       => 'db.example.com',
    'username'   => 'app',
    'password'   => 'secret',
    'database'   => 'production',
    'ssl'        => true,
    'ssl_ca'     => '/etc/ssl/certs/ca-bundle.crt',
    'ssl_verify' => true,
]);

// Mutual TLS — client certificate and key
$client = new MysqlClient([
    'host'       => 'db.example.com',
    'username'   => 'app',
    'password'   => 'secret',
    'database'   => 'production',
    'ssl'        => true,
    'ssl_ca'     => '/path/to/ca.pem',
    'ssl_cert'   => '/path/to/client-cert.pem',
    'ssl_key'    => '/path/to/client-key.pem',
    'ssl_verify' => true,
]);
```

The SSL upgrade happens during the MySQL handshake using PHP's `stream_socket_enable_crypto()`, negotiating **TLS 1.2 or TLS 1.3**. If `ssl: true` is set but the server does not advertise the `CLIENT_SSL` capability, the connection is rejected with a `ConnectionException` rather than falling back to plaintext.

---

## zlib compression

```php
$client = new MysqlClient([
    'host'     => 'db.example.com',
    'username' => 'app',
    'password' => 'secret',
    'database' => 'production',
    'compress' => true,
]);
```

Compression is negotiated at handshake time via the `CLIENT_COMPRESS` capability flag. If the server does not support compression, the connection proceeds without it. PHP's `zlib` extension must be loaded.

**When to enable compression:** the server is remote with limited bandwidth and queries return large result sets. **When to leave it disabled:** the server is on the same machine or local network, as compression overhead outweighs any bandwidth saving for small, frequent queries.

---

## Query cancellation

Server-side query cancellation is **disabled by default**. When disabled, `$promise->cancel()` transitions the promise to the cancelled state on the client side only — the MySQL server continues executing the query to completion and the connection remains checked out of the pool until it finishes.

Enable it explicitly for long-running queries where stopping server execution and releasing locks immediately has meaningful value:

```php
$client = new MysqlClient(
    config: $config,
    enableServerSideCancellation: true,
);

$promise = $client->query('SELECT * FROM huge_table');
Loop::addTimer(5.0, fn() => $promise->cancel()); // KILL QUERY dispatched
```

When enabled, cancelling a query promise dispatches `KILL QUERY <thread_id>` via a **dedicated side-channel TCP connection**. The pool then absorbs any stale kill flag with `DO SLEEP(0)` before returning the connection to normal use.

> **Note:** `commit()` and `rollback()` are never cancellable regardless of this setting.

---

## onConnect hook

```php
$client = new MysqlClient(
    config: $config,
    onConnect: function (ConnectionSetup $setup) {
        await($setup->execute("SET SESSION time_zone = '+00:00'"));
        await($setup->execute("SET SESSION sql_mode = 'STRICT_TRANS_TABLES'"));
    }
);
```

> If `resetConnection` is enabled, `COM_RESET_CONNECTION` wipes all session variables. The `onConnect` hook is therefore **re-invoked after every reset** to restore session state.

---

## Statement caching

Prepared statements are cached **per connection** (default: 256 slots, LRU eviction).

```php
$client = new MysqlClient(
    config: $config,
    enableStatementCache: true,
    statementCacheSize: 512
);

$client->clearStatementCache(); // Invalidate all caches (e.g. after schema changes)
```

> When `resetConnection` is enabled, the per-connection cache is automatically cleared on checkout because `COM_RESET_CONNECTION` drops all server-side statement handles.

---

## Result inspection

```php
$result = await($client->query('SELECT * FROM users'));

echo $result->rowCount;      // int — rows in result set
echo $result->affectedRows;  // int — rows affected by INSERT/UPDATE/DELETE
echo $result->lastInsertId;  // int — last auto-increment ID
echo $result->warningCount;  // int — MySQL warnings generated
echo $result->connectionId;  // int — server thread ID
echo $result->columnCount;   // int — number of columns

foreach ($result->fields as $col) {
    echo $col->name . ': ' . $col->typeName; // e.g. "price: DECIMAL"
}

foreach ($result as $row) { echo $row['name']; }

$row   = $result->fetchOne();
$all   = $result->fetchAll();
$col   = $result->fetchColumn('name');
```

---

## Numeric type handling

When queries are executed via prepared statements (i.e. any call to `query()` or `execute()` with `$params`, or explicit `prepare()`), column values are decoded from the **MySQL binary protocol**. The PHP type you receive depends on the MySQL column type.

### Integer types

| MySQL type | PHP type returned | Notes |
|---|---|---|
| `TINYINT` | `int` | Signed or unsigned; sign conversion applied automatically |
| `SMALLINT` | `int` | Signed or unsigned |
| `MEDIUMINT` | `int` | Signed or unsigned |
| `INT` | `int` | Signed or unsigned |
| `BIGINT` (signed) | `int` | Native 64-bit integer on 64-bit PHP; always fits |
| `BIGINT UNSIGNED` (≤ `PHP_INT_MAX`) | `int` | Fast path on 64-bit PHP |
| `BIGINT UNSIGNED` (> `PHP_INT_MAX`) | `string` | BCMath string if `bcmath` is loaded; `sprintf('%.0f')` fallback otherwise — precision may be lost without `bcmath` |

`BIGINT` arithmetic on **32-bit PHP** always falls back to `string` (via BCMath when available, `sprintf` otherwise), because 32-bit PHP cannot represent 64-bit integers natively.

### Floating-point types

| MySQL type | PHP type returned | Notes |
|---|---|---|
| `FLOAT` | `float` | Decoded from 4 bytes using IEEE 754 single precision (`unpack('g', ...)`) |
| `DOUBLE` | `float` | Decoded from 8 bytes using IEEE 754 double precision (`unpack('e', ...)`) |

Both `FLOAT` and `DOUBLE` are returned as PHP `float`. Because PHP `float` is IEEE 754 double precision (64-bit), a MySQL `FLOAT` (32-bit) is widened on decode and you may observe minor rounding artefacts compared to the original single-precision value. If exact representation matters, store the value as `DECIMAL` instead.

### Decimal and string-encoded types

| MySQL type | PHP type returned | Notes |
|---|---|---|
| `DECIMAL` / `NUMERIC` | `string` | Always returned as a string to preserve exact precision. Never cast to `float`. |
| `CHAR`, `VARCHAR`, `TEXT` variants | `string` | |
| `BINARY`, `VARBINARY`, `BLOB` variants | `string` (raw bytes) | |
| `JSON` | `string` | Raw JSON string; decode with `json_decode()` as needed |
| `DATE` | `string` | Format: `'YYYY-MM-DD'`; zero date returns `'0000-00-00'` |
| `DATETIME`, `TIMESTAMP` | `string` | Format: `'YYYY-MM-DD HH:MM:SS'` or `'YYYY-MM-DD HH:MM:SS.ffffff'` if microseconds are non-zero |
| `TIME` | `string` | Format: `'HH:MM:SS'` or `'HH:MM:SS.ffffff'`; supports negative values and day overflow (e.g. `'838:59:59'`) |
| `YEAR` | `int` | Decoded as a 2-byte integer |
| `BIT` | `string` (raw bytes) | |
| `NULL` | `null` | Null bitmap checked before decode; always `null` |

### Key rule: never cast `DECIMAL` to `float`

`DECIMAL` columns are intentionally returned as strings. Casting them to `float` silently discards precision:

```php
$result = await($client->query(
    'SELECT price FROM products WHERE id = :id',
    ['id' => 1]
));

$row = $result->fetchOne();

// Correct — preserve the exact string value from the server
$price = $row['price']; // "19.99" (string)

// Also correct — use bcmath for arithmetic on decimals
$tax   = bcmul($row['price'], '0.20', 2); // "4.00"
$total = bcadd($row['price'], $tax, 2);   // "23.99"

// WRONG — loses precision for large or high-decimal-place values
$price = (float) $row['price']; // may not round-trip exactly
```

### `bcmath` recommendation

The `bcmath` extension is strongly recommended for any application that handles financial data, high-precision arithmetic, or `BIGINT UNSIGNED` values near `PHP_INT_MAX`. Without it, those edge cases fall back to `sprintf('%.0f', ...)` which may silently lose precision for very large integers.

---

## Multiple result sets

```php
$result = await($client->query('CALL get_user_with_orders(?)', [$userId]));

foreach ($result as $row) { ... }            // first result set

$next = $result->nextResult();
if ($next !== null) {
    foreach ($next as $row) { ... }          // second result set
}
```

---

## API Reference (Summary)

### `MysqlClient`

Implements `Hibla\Sql\SqlClientInterface`.

| Method / Property | Returns | Description |
|---|---|---|
| `$stats` | `array<string, int\|bool>` | Snapshot of pool state. No database round-trip. |
| `query(string $sql, array $params = [])` | `Promise<MysqlResult>` | Execute a query. Uses binary protocol when params are given. Supports named params. |
| `execute(string $sql, array $params = [])` | `Promise<int>` | Execute and return affected row count. |
| `executeGetId(string $sql, array $params = [])` | `Promise<int>` | Execute and return last insert ID. |
| `fetchOne(string $sql, array $params = [])` | `Promise<array\|null>` | First row as associative array, or null. |
| `fetchValue(string $sql, $column = null, array $params = [])` | `Promise<mixed>` | Single scalar value from first row. |
| `prepare(string $sql)` | `Promise<ManagedPreparedStatement>` | Prepare a reusable statement. Supports named params. |
| `stream(string $sql, array $params = [], int $bufferSize = 100)` | `Promise<MysqlRowStream>` | Stream rows with backpressure. Supports named params. |
| `beginTransaction(?IsolationLevelInterface $level = null)` | `Promise<TransactionInterface>` | Begin a transaction manually. |
| `transaction(callable $callback, ?TransactionOptions $options = null)` | `Promise<mixed>` | Run a transaction with automatic commit/rollback and optional retry. |
| `healthCheck()` | `Promise<array<string, int>>` | Pings all idle connections and returns a summary. |
| `clearStatementCache()` | `void` | Invalidate all per-connection statement caches. |
| `close()` | `void` | Force-close all connections immediately. |
| `closeAsync(float $timeout = 0.0)` | `Promise<void>` | Graceful shutdown; waits for active queries to finish. |

### `PreparedStatementInterface` (`ManagedPreparedStatement`)

| Method | Returns | Description |
|---|---|---|
| `execute(array $params = [])` | `Promise<MysqlResult>` | Execute with given parameters. Supports named params. |
| `executeStream(array $params = [], int $bufferSize = 100)` | `Promise<MysqlRowStream>` | Execute and stream results. Supports named params. |
| `close()` | `Promise<void>` | Send `COM_STMT_CLOSE` and release connection to pool. |

### `TransactionInterface`

Implements `Hibla\Sql\Transaction`.

| Method | Returns | Description |
|---|---|---|
| `query(string $sql, array $params = [])` | `Promise<MysqlResult>` | Execute a query inside the transaction. Supports named params. |
| `execute(string $sql, array $params = [])` | `Promise<int>` | Execute and return affected rows. |
| `executeGetId(string $sql, array $params = [])` | `Promise<int>` | Execute and return last insert ID. |
| `fetchOne(string $sql, array $params = [])` | `Promise<array\|null>` | First row or null. |
| `fetchValue(string $sql, $column = null, array $params = [])` | `Promise<mixed>` | Scalar value from first row. |
| `stream(string $sql, array $params = [], int $bufferSize = 100)` | `Promise<MysqlRowStream>` | Stream rows inside the transaction. |
| `prepare(string $sql)` | `Promise<PreparedStatementInterface>` | Prepare a statement scoped to this transaction. |
| `commit()` | `Promise<void>` | Commit and release connection. |
| `rollback()` | `Promise<void>` | Roll back and release connection. |
| `savepoint(string $identifier)` | `Promise<void>` | Create a savepoint. |
| `rollbackTo(string $identifier)` | `Promise<void>` | Roll back to savepoint (clears tainted state). |
| `releaseSavepoint(string $identifier)` | `Promise<void>` | Release a savepoint. |
| `onCommit(callable $callback)` | `void` | Register a callback to run after commit. |
| `onRollback(callable $callback)` | `void` | Register a callback to run after rollback. |

---

## Exceptions

All database exceptions extend `Hibla\Sql\Exceptions\SqlException`.

| Exception | Thrown when |
|---|---|
| `QueryException` | General query execution error |
| `PreparedException` | `COM_STMT_PREPARE` fails, or statement is used after close |
| `ConnectionException` | TCP connection fails, drops unexpectedly, or is closed |
| `AuthenticationException` | MySQL authentication fails |
| `ConstraintViolationException` | UNIQUE, FOREIGN KEY, NOT NULL, or CHECK constraint violated |
| `DeadlockException` | MySQL error 1213 — deadlock detected |
| `LockWaitTimeoutException` | MySQL error 1205 — lock wait timeout exceeded |
| `PoolException` | Pool exhausted, shutting down, or max waiters exceeded |
| `NotInitializedException` | `MysqlClient` method called after `close()` |
| `ConfigurationException` | Invalid configuration passed to `MysqlClient` constructor |

---

## Development

### Requirements

- Docker and Docker Compose
- PHP 8.4+
- Composer

### Setup

```bash
git clone https://github.com/hiblaphp/mysql.git
cd mysql
composer install
```

### Running tests

The test suite requires a running database. Each supported server has a dedicated
Docker Compose service pair: one plain TCP and one SSL and a matching Composer
script that sets the correct port environment variables before running Pest.

**Start the database services you want to test against:**

```bash
# MySQL 8.0 (plain + SSL)
docker compose up -d mysql mysql_ssl

# MySQL 9.0 (plain + SSL)
docker compose up -d mysql90 mysql90_ssl

# MariaDB LTS (plain + SSL)
docker compose up -d mariadb mariadb_ssl
```

Wait for the containers to report healthy before running tests:

```bash
docker ps  # all target containers should show (healthy)
```

**Run the tests for a specific server:**

```bash
# MySQL 8.0  — connects to ports 3310 (plain) and 3307 (SSL)
composer test:mysql

# MySQL 9.0  — connects to ports 3313 (plain) and 3314 (SSL)
composer test:mysql90

# MariaDB LTS — connects to ports 3311 (plain) and 3308 (SSL)
composer test:mariadb
```

**Tear down services when done:**

```bash
docker compose down -v
```

### Static analysis

```bash
composer analyze
```

### Code formatting

```bash
composer format
```

### Port reference

| Service | Plain port | SSL port |
|---|---|---|
| MySQL 8.0 | 3310 | 3307 |
| MySQL 9.0 | 3313 | 3314 |
| MariaDB LTS | 3311 | 3308 |

All ports are defined in `docker-compose.yml`. The Composer test scripts set
`MYSQL_PORT` and `MYSQL_SSL_PORT` automatically and you do not need to export
them manually unless you want to point the suite at an external server.

---

## Credits

- Built on [hiblaphp/socket](https://github.com/hiblaphp/socket) for async socket I/O.
- Implements [hiblaphp/sql](https://github.com/hiblaphp/sql) contracts for a common, swappable database interface.
- Uses [rcalicdan/mysql-binary-protocol](https://github.com/rcalicdan/mysql-binary-protocol) for MySQL binary packet handling and protocol state machines.

---

## License

MIT License. See [LICENSE](./LICENSE) for more information.
