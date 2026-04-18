# Hibla MySQL Client
**A modern, async-first, high-performance MySQL client for PHP with robust connection pooling, prepared statements, streaming, and full transaction support.**

[![Latest Release](https://img.shields.io/github/release/hiblaphp/mysql.svg?style=flat-square)](https://github.com/hiblaphp/mysql/releases)
[![Tests](https://github.com/hiblaphp/mysql/actions/workflows/test.yml/badge.svg)](https://github.com/hiblaphp/mysql/actions/workflows/test.yml)
[![Total Downloads](https://img.shields.io/packagist/dt/hiblaphp/mysql.svg?style=flat-square)](https://packagist.org/packages/hiblaphp/mysql)
[![MIT License](https://img.shields.io/badge/license-MIT-blue.svg?style=flat-square)](./LICENSE)

---

## Features

| Feature | Status | Notes |
|---|---|---|
| Lazy connection pooling | Supported | No TCP connections opened until the first query |
| Async/await execution | Supported | All operations return `PromiseInterface`; `await()` safe everywhere |
| Parameterized queries | Supported | Binary protocol via prepared statements; SQL-injection safe |
| Prepared statements | Supported | Explicit lifecycle control with `prepare()` / `close()` |
| Statement caching | Supported | Per-connection LRU cache; eliminates repeated `COM_STMT_PREPARE` round-trips |
| Streaming results | Supported | Row-by-row delivery with backpressure; supports large result sets |
| Transactions | Supported | High-level `transaction()` with auto commit/rollback and retry; low-level `beginTransaction()` |
| Savepoints | Supported | Full `SAVEPOINT` / `ROLLBACK TO` / `RELEASE SAVEPOINT` support |
| Isolation levels | Supported | Per-transaction scoping; session isolation level is never mutated |
| Stored procedures | Supported | Multi-result-set chains via `nextResult()` |
| Multi-statements | Supported | Disabled by default; see [security warning](#multi-statements) |
| SSL/TLS | Supported | TLS 1.2/1.3; optional mutual TLS and certificate verification |
| zlib compression | Supported | MySQL protocol compression via `CLIENT_COMPRESS` |
| Server-side query cancellation | Supported | Opt-in `KILL QUERY` via side-channel TCP connection |
| Health checks | Supported | `healthCheck()` pings idle connections; evicts stale ones |
| Pool stats | Supported | `$client->stats` for live pool introspection |
| `hiblaphp/sql` contracts | Supported | Fully implements `SqlClientInterface`; drivers are swappable |
| Named parameters (`:name`) | Planned | Only positional `?` placeholders supported currently |
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
- [SSL/TLS](#ssltls)
- [zlib compression](#zlib-compression)
- [Query cancellation](#query-cancellation)
- [onConnect hook](#onconnect-hook)
- [Statement caching](#statement-caching)

**Working with responses**
- [Result inspection](#result-inspection)
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

```bash
composer require hiblaphp/mysql
```

**Requirements:**

| Requirement | Version | Notes |
|---|---|---|
| PHP | 8.4+ | |
| `hiblaphp/promise` | — | |
| `hiblaphp/event-loop` | — | |
| `hiblaphp/async` | — | |
| `hiblaphp/socket` | — | |
| `hiblaphp/sql` | — | |
| `rcalicdan/mysql-binary-protocol` | — | |

**PHP extensions:**

| Extension | Required | Notes |
|---|---|---|
| `openssl` | Optional | Required for SSL/TLS connections. Must be enabled if `ssl: true` is set in config. If not enabled, the connection will be rejected at handshake time if the extension is unavailable. See [SSL/TLS](#ssltls). |
| `zlib` | Optional | Required for MySQL protocol compression. Must be loaded if `compress: true` is set in config. Included in most standard PHP builds. See [zlib compression](#zlib-compression). |
| `bcmath` | Optional | Required for precise decimal and float handling when using binary prepared statements. Without it, `DECIMAL` and `FLOAT` column values returned via the binary protocol may lose precision due to floating-point representation. Strongly recommended for any application handling financial or high-precision numeric data. |

All three extensions are optional at install time but will be needed at runtime if you use the features they support. Most standard PHP builds ship with all three enabled and run `php -m` to verify which extensions are available in your environment.

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

// Prepared statement (recommended for repeated execution)
$stmt = await($client->prepare('SELECT * FROM users WHERE email = ?'));
$result = await($stmt->execute(['alice@example.com']));
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
| `$maxWaiters` | `int` | `0` | Maximum number of callers that may queue waiting for a free connection before a `PoolException` is thrown immediately. `0` means unlimited this means callers will always queue and wait up to `$acquireTimeout`. Set a non-zero value to shed load fast under pressure rather than letting the wait queue grow unbounded. |
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

### Convenience methods

```php
// Returns affected row count
$count = await($client->execute('UPDATE users SET last_login = NOW() WHERE id = ?', [$id]));

// Returns last insert ID
$lastId = await($client->executeGetId('INSERT INTO users (name, email) VALUES (?, ?)', [
    'Alice', 'alice@example.com'
]));

// Returns first row as associative array, or null
$user = await($client->fetchOne('SELECT * FROM users WHERE id = ?', [$id]));

// Returns value of first column (or named column) from first row
$name = await($client->fetchValue('SELECT name FROM users WHERE id = ?', [$id]));
```

---

## Prepared statements

Use explicit prepared statements when you need to execute the same query many times and want direct control over the statement lifecycle.

```php
$stmt = await($client->prepare('SELECT * FROM products WHERE category_id = ? AND price > ?'));

$result1 = await($stmt->execute([1, 50.00]));
$result2 = await($stmt->execute([2, 100.00]));

$stmt->close(); // Sends COM_STMT_CLOSE; called automatically on destruct if omitted
```

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

You can also stream **prepared statement** results:

```php
$stmt = await($client->prepare('SELECT * FROM logs WHERE created_at > ?'));
$stream = await($stmt->executeStream([$since]));

// Stream metadata is available here too
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

// Simple transaction — commit and rollback are handled automatically.
// await() is safe here; the callback runs inside an implicit async() fiber.
$result = await($client->transaction(function (TransactionInterface $tx) use ($from, $to) {
    await($tx->execute('UPDATE accounts SET balance = balance - 100 WHERE id = ?', [$from]));
    await($tx->execute('UPDATE accounts SET balance = balance + 100 WHERE id = ?', [$to]));

    return 'Transfer completed';
}));
```

**Partial failure is never silently committed.** If any `await()` inside the callback throws, whether from a query error, a constraint violation, or application code so the client automatically rolls back the entire transaction and re-throws the exception. There is no risk of a half-applied transaction reaching the database.

```php
// If the second execute throws (e.g. constraint violation),
// the first execute is automatically rolled back. The database
// is never left in a partially updated state.
await($client->transaction(function (TransactionInterface $tx) use ($from, $to) {
    await($tx->execute('UPDATE accounts SET balance = balance - 100 WHERE id = ?', [$from]));
    await($tx->execute('UPDATE accounts SET balance = balance + 100 WHERE id = ?', [$to])); // throws
    // ^ automatic ROLLBACK — the debit above is undone
}));
```

**Retry on transient failures** such as deadlocks and lock wait timeouts is built in via `TransactionOptions`. The entire callback is re-run from scratch on each attempt, so the retry is always a clean transaction with no partial state carried over.

```php
// Retry up to 3 times on deadlock or lock wait timeout.
// Each attempt starts a fresh transaction — no partial state is ever carried over.
// The isolation level is applied per-transaction and never leaks into the session.
await($client->transaction(
    function (TransactionInterface $tx) use ($from, $to) {
        await($tx->execute('UPDATE accounts SET balance = balance - 100 WHERE id = ?', [$from]));
        await($tx->execute('UPDATE accounts SET balance = balance + 100 WHERE id = ?', [$to]));
    },
    TransactionOptions::default()
        ->withAttempts(3)
        ->withIsolationLevel(IsolationLevel::REPEATABLE_READ)
));
```

The full query surface including streaming is available inside the callback. Because the callback runs in a fiber, streaming large result sets while issuing writes does not block other concurrent work on the event loop.

```php
await($client->transaction(function (TransactionInterface $tx) {
    $stream = await($tx->stream('SELECT * FROM large_table WHERE status = ?', ['pending']));

    foreach ($stream as $row) {
        // Each await() here yields back to the event loop while the write is in flight,
        // so other concurrent fibers continue to make progress.
        await($tx->execute('UPDATE large_table SET status = ? WHERE id = ?', ['done', $row['id']]));
    }
}));
```

---

### Low-level API: `beginTransaction()`

Use `beginTransaction()` when you need explicit control over the transaction lifecycle and for example, when the commit or rollback decision depends on logic that cannot be expressed as a single callback, or when you need to hold a transaction open across multiple await points in your own fiber.

```php
$tx = await($client->beginTransaction());
try {
    await($tx->execute('UPDATE accounts SET balance = balance - 100 WHERE id = ?', [$from]));
    await($tx->execute('UPDATE accounts SET balance = balance + 100 WHERE id = ?', [$to]));
    await($tx->commit());
} catch (\Throwable $e) {
    await($tx->rollback());
    throw $e;
}
```

Unlike `transaction()`, the low-level API does **not** retry automatically and does **not** wrap the work in a fiber so you are responsible for the full lifecycle. Prefer `transaction()` in all cases where it is sufficient.

> **Anti-pattern — relying on GC to roll back:**
> ```php
> $tx = await($client->beginTransaction());
> await($tx->execute('UPDATE accounts SET balance = 0 WHERE id = ?', [$id]));
> // $tx goes out of scope — ROLLBACK is issued automatically, but on a best-effort
> // basis only. The destructor cannot await, so the rollback may be lost under load.
> ```
> Always call `commit()` or `rollback()` explicitly. The automatic rollback on GC is a
> safety net, not a substitute for explicit lifecycle management.

---

### Savepoints

Savepoints let you mark a point within a transaction and roll back to it selectively without abandoning the entire transaction. This is the correct tool when you want to attempt a risky operation but recover from its failure without losing earlier work in the same transaction.

**Without savepoints**, any exception inside `transaction()` triggers a full rollback of everything — there is no partial recovery. If you need finer-grained control, establish a savepoint before the risky section:

```php
await($client->transaction(function (TransactionInterface $tx) {
    // This work is safe and committed regardless of what happens below.
    await($tx->execute('INSERT INTO audit_log (event) VALUES (?)', ['attempt']));

    await($tx->savepoint('before_risky_op'));

    try {
        await($tx->execute('INSERT INTO external_refs (id) VALUES (?)', [$externalId]));
    } catch (\Throwable $e) {
        // Roll back only the risky section. The audit_log insert above is preserved.
        await($tx->rollbackTo('before_risky_op'));
    }

    await($tx->releaseSavepoint('before_risky_op'));

    // Commit includes the audit_log insert but not the failed external_refs insert.
}));
```

Rolling back to a savepoint also **clears the tainted state** on the transaction, so you can continue issuing queries after a partial rollback without the client rejecting them.

---

### Transaction lifecycle rules

**Isolation level scoping.** Isolation levels are applied via `SET TRANSACTION ISOLATION LEVEL` immediately before `START TRANSACTION`, scoping them strictly to that transaction. The session isolation level is never mutated, so concurrent transactions on the same pool are never affected.

**Tainted state.** If any query inside a transaction throws, the transaction is marked tainted. The client will reject all further queries on that transaction until you call `rollback()` or roll back to a savepoint via `rollbackTo()`. This prevents accidental partial commits where some queries succeeded before the failure.

**Automatic rollback on partial failure.** When using `transaction()`, any unhandled exception from the callback causes an automatic `ROLLBACK` before the exception propagates to the caller. The transaction is never left open and the connection is returned to the pool cleanly.

**GC safety net.** If a `Transaction` object is garbage collected without an explicit `commit()` or `rollback()`, a `ROLLBACK` is issued automatically and the connection is returned to the pool. This is a best-effort safety net only — the destructor cannot `await`, so the rollback may be abandoned if the event loop is under pressure. Always manage the lifecycle explicitly.

**`commit()` and `rollback()` are not cancellable.** Dispatching `KILL QUERY` against a commit or rollback would leave the transaction in an undefined state on the server. These operations always run to completion regardless of the `enableServerSideCancellation` setting.

---

## Stored procedures

Stored procedures are fully supported. Because a `CALL` statement may return multiple result sets (one per `SELECT` inside the procedure, plus a final status `OK` packet), the client models them as a **linked chain** of result objects you can walk with `nextResult()`.

```php
// Call a stored procedure
$result = await($client->query('CALL get_user_with_orders(?)', [$userId]));

// First result set — e.g. the user row
foreach ($result as $row) {
    echo $row['name'];
}

// Second result set — e.g. the user's orders
$orders = $result->nextResult();
if ($orders !== null) {
    foreach ($orders as $order) {
        echo $order['total'];
    }
}
```

Stored procedures work identically inside transactions:

```php
await($client->transaction(function (TransactionInterface $tx) use ($userId) {
    $result = await($tx->query('CALL transfer_funds(?, ?, ?)', [$from, $to, 100]));

    // Inspect the procedure's result sets
    $summary = $result->fetchOne();
    echo $summary['status'];
}));
```

---

## Multi-statements

> **Security warning, this is disabled by default.** Multi-statement support allows multiple SQL statements separated by `;` to be sent in a single call. This significantly increases the blast radius of a SQL injection vulnerability: a successful injection can chain arbitrary additional statements in the same round-trip. Only enable this if you have a genuine need for it and fully understand the risk.

Multi-statements are disabled by default. Enable them explicitly via the `multi_statements` config option:

```php
$client = new MysqlClient([
    'host'             => 'localhost',
    'username'         => 'root',
    'password'         => '',
    'database'         => 'app',
    'multi_statements' => true, // disabled by default, see security warning above
]);
```

Once enabled, you can send multiple statements in a single `query()` call. The results are returned as a linked chain traversable via `nextResult()`, identical to stored procedure results:

```php
$result = await($client->query('SELECT * FROM users; SELECT * FROM orders; SELECT COUNT(*) FROM stats'));

// First result set — users
foreach ($result as $row) { ... }

// Second result set — orders
$orders = $result->nextResult();
foreach ($orders as $row) { ... }

// Third result set — count
$stats = $orders->nextResult();
$count = $stats->fetchOne();
```

Multi-statements can also be used inside transactions:

```php
await($client->transaction(function (TransactionInterface $tx) {
    $result = await($tx->query(
        'UPDATE accounts SET balance = balance - 100 WHERE id = 1;
         UPDATE accounts SET balance = balance + 100 WHERE id = 2'
    ));
}));
```

> **Multi-statements vs stored procedures:** Stored procedures (`CALL`) are safe with the default configuration and are the preferred way to group multiple queries on the server. Multi-statements should only be considered when you need ad-hoc batching from the client side and your application guarantees that the SQL is never user-influenced.

---

## Connection pooling

The pool manages the full connection lifecycle automatically. By default it is **fully lazy** (`minConnections: 0`), so no TCP connections are opened until the first query is dispatched, keeping startup cost at zero.

```php
$client = new MysqlClient(
    config: $config,
    minConnections: 0,        // lazy, connections created on demand only
    maxConnections: 50,       // hard cap on open connections (default: 10)
    idleTimeout: 600,         // seconds before idle connections are evicted (default: 60)
    maxLifetime: 3600,        // seconds before a connection is rotated regardless of use
    acquireTimeout: 10.0,     // seconds to wait for a free connection before failing (default: 10.0)
    resetConnection: true,    // send COM_RESET_CONNECTION on pool release (default: false)
);
```

Set `minConnections > 0` only if you need pre-warmed connections at startup to absorb an immediate burst of traffic without waiting for the TCP handshake and MySQL authentication on the first requests.

### Check-on-borrow health strategy

The pool uses a **check-on-borrow** strategy to validate connections before handing them to a caller. Before a connection is checked out of the pool, the client verifies it is still alive, catching stale connections that were silently dropped by the server, a proxy, or a firewall while sitting idle. A connection that fails the check is discarded and replaced transparently, so callers never receive a dead connection. This does add a small validation step on every borrow; if this overhead is a concern for very high-frequency short queries, pair it with `resetConnection: false` and a conservative `idleTimeout` to reduce the number of connections that age to the point of needing validation.

### Shutdown strategies

`MysqlClient` registers a destructor that issues a force-close automatically when the object is garbage collected, including at the end of a normal script run. This means connections are never silently leaked if you forget to call `close()`. That said, explicit shutdown is still strongly recommended for production code: the destructor cannot `await` anything, so any queries still in flight at GC time are abandoned rather than drained gracefully.

```php
// Graceful — stops new work, waits for active queries to finish, then closes
await($client->closeAsync(timeout: 30.0));

// Force — closes everything immediately, rejects pending waiters
$client->close();

// Acceptable for short CLI scripts where abrupt teardown is fine.
// The destructor will force-close automatically when $client goes out of scope.
unset($client);
```

### `resetConnection` and statement cache interaction

When `resetConnection` is enabled, `COM_RESET_CONNECTION` wipes all server-side prepared statement handles. The client automatically clears the per-connection statement cache on checkout to prevent executing stale statement IDs. The `onConnect` hook (if set) is also **re-run after every reset** to restore session state.

---

## Health checks & pool stats

### Health check

`healthCheck()` pings all idle connections in the pool and returns a summary of results. Stale connections that fail the ping are discarded and not returned to the pool.

```php
$result = await($client->healthCheck());
// e.g. ['checked' => 5, 'failed' => 1, 'evicted' => 1]
```

This is useful in readiness probes or startup checks to verify the database is reachable before accepting traffic.

### Pool stats

The `$stats` property returns a snapshot of the current pool state without touching the database:

```php
$stats = $client->stats;
// e.g. [
//     'total'   => 8,   // total open connections
//     'idle'    => 5,   // connections sitting in the pool
//     'active'  => 3,   // connections currently executing a query or transaction
//     'waiting' => 0,   // callers waiting for a free connection
// ]
```

---

## Configuration options

All options can be passed via DSN, array, or `MysqlConfig` object.

| Option | Type | Default | Description |
|---|---|---|---|
| `host` | string | — | MySQL server hostname or IP |
| `port` | int | `3306` | TCP port |
| `username` | string | `'root'` | MySQL username |
| `password` | string | `''` | MySQL password |
| `database` | string | `''` | Default schema |
| `charset` | string | `'utf8mb4'` | Connection character set |
| `connect_timeout` | int | `10` | Seconds before a connect attempt is aborted |
| `ssl` | bool | `false` | Require SSL/TLS — see [SSL/TLS](#ssltls) |
| `ssl_ca` | string\|null | `null` | Path to CA certificate |
| `ssl_cert` | string\|null | `null` | Path to client certificate |
| `ssl_key` | string\|null | `null` | Path to client key |
| `ssl_verify` | bool | `false` | Verify server certificate |
| `compress` | bool | `false` | Enable zlib protocol compression — see [zlib compression](#zlib-compression) |
| `enable_server_side_cancellation` | bool | `false` | Dispatch `KILL QUERY` on promise cancellation |
| `kill_timeout_seconds` | float | `3.0` | Timeout for the `KILL QUERY` side-channel |
| `reset_connection` | bool | `false` | Send `COM_RESET_CONNECTION` on pool release |
| `multi_statements` | bool | `false` | Allow stacked queries — **security risk, use with care** |

Example using an array:

```php
$client = new MysqlClient([
    'host'                            => 'localhost',
    'username'                        => 'root',
    'password'                        => '',
    'database'                        => 'app',
    'charset'                         => 'utf8mb4',
    'enable_server_side_cancellation' => true,
    'reset_connection'                => true,
    'compress'                        => true,
    'ssl'                             => true,
    'ssl_verify'                      => true,
]);
```

---

## Limitations

The following features are **not currently supported** and are planned for a future release:

| Feature | Notes |
|---|---|
| `LOAD DATA LOCAL INFILE` | Local infile is not implemented. Attempting to use it will result in a `QueryException`. |
| Named prepared statement parameters (`:name` syntax) | Only positional `?` placeholders are supported. Named parameters such as `:userId` or `:email` are not recognized by the binary protocol layer. |

Both of these are on the roadmap and will be addressed in a future release.

---

## SSL/TLS

SSL/TLS support is built in. Enable it via the `ssl` option and, optionally, supply certificate paths for mutual TLS or custom CA verification.

```php
// Require SSL — server certificate not verified (useful for self-signed certs)
$client = new MysqlClient([
    'host'     => 'db.example.com',
    'username' => 'app',
    'password' => 'secret',
    'database' => 'production',
    'ssl'      => true,
]);

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

The client supports the **MySQL protocol compression** extension, which compresses packets using PHP's built-in **zlib** extension before they are written to the socket. This can substantially reduce bandwidth usage for large result sets or high-throughput workloads where network I/O is the bottleneck.

```php
$client = new MysqlClient([
    'host'     => 'db.example.com',
    'username' => 'app',
    'password' => 'secret',
    'database' => 'production',
    'compress' => true,
]);
```

Compression is negotiated at handshake time via the `CLIENT_COMPRESS` capability flag. If the server does not support compression, the connection proceeds without it and no error is raised. PHP's `zlib` extension must be loaded (it is included in most standard PHP builds).

**When to enable compression:**

- The MySQL server is on a remote host where network bandwidth is limited.
- Queries return large result sets (e.g. wide tables, BLOB columns).
- CPU on both client and server is plentiful relative to available network throughput.

**When to leave it disabled:**

- The server is on the same machine or a local network (loopback, LAN), as compression overhead outweighs any bandwidth saving.
- Queries are small and frequent, as the per-packet overhead is not worth it.

Compression and SSL can be used together without restriction.

---

## Query cancellation

Server-side query cancellation is **disabled by default**. When disabled, calling `$promise->cancel()` transitions the promise to the cancelled state immediately on the client side, so your code stops waiting and the promise is released for garbage collection. However, **the MySQL server has no knowledge of the cancellation**: it continues executing the query to completion on its own thread. The connection remains checked out of the pool and unavailable until the server finishes and returns its response, at which point the result is silently discarded and the connection is returned to the pool normally.

In short, client-side cancellation without server-side cancellation enabled is a *local opt-out*, not a true query abort. It is useful when you no longer care about the result but can tolerate the connection being held until the server finishes on its own schedule.

**Why it is disabled by default:**

- **It is pointless for typical fast queries.** The overwhelming majority of queries, including simple lookups, indexed reads, and small writes, complete in milliseconds. By the time a cancel is dispatched and the `KILL QUERY` side-channel connection is established, the original query has almost certainly already finished. Paying the overhead of an extra TCP connection per cancellation for queries that finish in under 5 ms provides no real benefit.
- A proxy or load balancer may route the kill connection to a different backend node, making the kill ineffective.
- Connection quotas make additional side-channel connections unacceptable in some environments.
- Query duration is often already bounded by server-side timeouts (e.g. `max_execution_time`), making client-initiated cancellation redundant.

Enable it explicitly only when your workload includes genuinely long-running queries such as full-table scans, heavy aggregations, or report generation, where stopping server execution and releasing locks immediately has meaningful value:

```php
$client = new MysqlClient(
    config: $config,
    enableServerSideCancellation: true,
);
```

When enabled, cancelling a query promise dispatches `KILL QUERY <thread_id>` to the server via a **dedicated side-channel TCP connection**. This stops the server-side query immediately and releases locks. The pool then absorbs any stale kill flag left by MySQL with `DO SLEEP(0)` before returning the connection to normal use.

```php
$promise = $client->query('SELECT * FROM huge_table');
Loop::addTimer(5.0, fn() => $promise->cancel()); // KILL QUERY sent if cancellation is enabled
```

> **Note:** `commit()` and `rollback()` are never cancellable regardless of this setting, as dispatching `KILL QUERY` against a commit or rollback would leave the transaction in an undefined state on the server.

---

## onConnect hook

Run initialization logic on every **new physical connection**, immediately after the MySQL handshake completes. The hook receives a `ConnectionSetup` interface, which is a minimal surface that exposes only `query()` and `execute()` to prevent internal connection objects from leaking.

```php
$client = new MysqlClient(
    config: $config,
    onConnect: function (ConnectionSetup $setup) {
        await($setup->execute("SET SESSION time_zone = '+00:00'"));
        await($setup->execute("SET SESSION sql_mode = 'STRICT_TRANS_TABLES'"));
    }
);
```

> **Important:** If `resetConnection` is enabled, `COM_RESET_CONNECTION` wipes all session variables back to server defaults, putting the connection in a state identical to immediately after the initial handshake. The `onConnect` hook is therefore **re-invoked after every reset** to restore session state. If the hook throws or rejects after a reset, the connection is dropped entirely rather than returned to the pool in an unknown state.

---

## Statement caching

Prepared statements are cached **per connection** (default: 256 slots, LRU eviction). This eliminates repeated `COM_STMT_PREPARE` round-trips for queries that are executed frequently.

Caching is enabled by default and is transparent, so `$client->query($sql, $params)` uses the cache automatically.

```php
$client = new MysqlClient(
    config: $config,
    enableStatementCache: true,  // default: true
    statementCacheSize: 512      // default: 256
);

// Invalidate all caches manually (e.g. after schema changes)
$client->clearStatementCache();
```

> When `resetConnection` is enabled, the per-connection cache is automatically cleared on checkout because `COM_RESET_CONNECTION` drops all server-side statement handles.

---

## Result inspection

```php
$result = await($client->query('SELECT * FROM users'));

// rowCount and other fields are properties, not methods
echo $result->rowCount;       // int, number of rows in result set
echo $result->affectedRows;   // int, rows affected by INSERT/UPDATE/DELETE
echo $result->lastInsertId;   // int, last auto-increment ID
echo $result->warningCount;   // int, MySQL warnings generated
echo $result->connectionId;   // int, server thread ID that executed the query
echo $result->columnCount;    // int, number of columns

// Column metadata
foreach ($result->fields as $col) {
    echo $col->name . ': ' . $col->typeName; // e.g. "id: INT UNSIGNED"
}

// Iterating rows
foreach ($result as $row) {
    echo $row['name'];
}

// Fetch helpers
$row   = $result->fetchOne();               // first row or null
$value = $result->fetchOne()['email'];      // specific field
$all   = $result->fetchAll();               // all rows as array
$col   = $result->fetchColumn('name');      // single column across all rows
```

---

## Multiple result sets

Stored procedures and `multi_statements` queries may return more than one result set. These are linked as a chain traversable via `nextResult()`:

```php
// Calling a stored procedure that returns multiple SELECTs
$result = await($client->query('CALL get_user_with_orders(?)', [$userId]));

// First result set (e.g. user row)
foreach ($result as $row) { ... }

// Second result set (e.g. orders)
$next = $result->nextResult();
if ($next !== null) {
    foreach ($next as $row) { ... }
}
```

> Stored procedures work out of the box. The `multi_statements` option (stacked raw SQL separated by `;`) is a separate feature that is disabled by default due to SQL injection risk. See [Multi-statements](#multi-statements) for details.

---

## API Reference (Summary)

### `MysqlClient`

Implements `Hibla\Sql\SqlClientInterface`.

| Method / Property | Returns | Description |
|---|---|---|
| `$stats` | `array<string, int\|bool>` | Snapshot of pool state: total, idle, active, and waiting connection counts. No database round-trip. |
| `query(string $sql, array $params = [])` | `Promise<MysqlResult>` | Execute a query. Uses binary protocol when params are given. |
| `execute(string $sql, array $params = [])` | `Promise<int>` | Execute and return affected row count. |
| `executeGetId(string $sql, array $params = [])` | `Promise<int>` | Execute and return last insert ID. |
| `fetchOne(string $sql, array $params = [])` | `Promise<array\|null>` | First row as associative array, or null. |
| `fetchValue(string $sql, $column = null, array $params = [])` | `Promise<mixed>` | Single scalar value from first row. |
| `prepare(string $sql)` | `Promise<ManagedPreparedStatement>` | Prepare a reusable statement. |
| `stream(string $sql, array $params = [], int $bufferSize = 100)` | `Promise<MysqlRowStream>` | Stream rows with backpressure. |
| `beginTransaction(?IsolationLevelInterface $level = null)` | `Promise<TransactionInterface>` | Begin a transaction manually. Isolation level is per-transaction, not session-wide. |
| `transaction(callable $callback, ?TransactionOptions $options = null)` | `Promise<mixed>` | Run a transaction with automatic commit/rollback and optional retry. Callback runs inside an implicit fiber. |
| `healthCheck()` | `Promise<array<string, int>>` | Pings all idle connections and returns a summary. Stale connections are evicted. |
| `clearStatementCache()` | `void` | Invalidate all per-connection statement caches. |
| `close()` | `void` | Force-close all connections immediately. |
| `closeAsync(float $timeout = 0.0)` | `Promise<void>` | Graceful shutdown; waits for active queries to finish. |

### `PreparedStatementInterface` (`ManagedPreparedStatement`)

| Method | Returns | Description |
|---|---|---|
| `execute(array $params = [])` | `Promise<MysqlResult>` | Execute with given parameters. |
| `executeStream(array $params = [], int $bufferSize = 100)` | `Promise<MysqlRowStream>` | Execute and stream results. |
| `close()` | `Promise<void>` | Send `COM_STMT_CLOSE` and release connection to pool. |

> If `close()` is never called, it is invoked automatically when the object is garbage collected.

### `TransactionInterface`

Implements `Hibla\Sql\Transaction`.

| Method | Returns | Description |
|---|---|---|
| `query(string $sql, array $params = [])` | `Promise<MysqlResult>` | Execute a query inside the transaction. |
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

```bash
git clone https://github.com/hiblaphp/mysql.git
cd mysql
composer install
```

```bash
./vendor/bin/pest
```

```bash
./vendor/bin/phpstan analyse
```

---

## Credits

- Built on [hiblaphp/socket](https://github.com/hiblaphp/socket) for async socket I/O.
- Implements [hiblaphp/sql](https://github.com/hiblaphp/sql) contracts for a common, swappable database interface.
- Uses [rcalicdan/mysql-binary-protocol](https://github.com/rcalicdan/mysql-binary-protocol) for MySQL binary packet handling and protocol state machines.

---

## License

MIT License. See [LICENSE](./LICENSE) for more information.