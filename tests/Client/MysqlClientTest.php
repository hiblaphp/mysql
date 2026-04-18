<?php

declare(strict_types=1);

use Hibla\Mysql\Exceptions\ConfigurationException;
use Hibla\Mysql\Exceptions\NotInitializedException;
use Hibla\Mysql\Exceptions\PoolException;
use Hibla\Mysql\Interfaces\ConnectionSetup;
use Hibla\Mysql\Internals\ManagedPreparedStatement;
use Hibla\Mysql\MysqlClient;
use Hibla\Promise\Exceptions\TimeoutException;
use Hibla\Promise\Interfaces\PromiseInterface;
use Hibla\Promise\Promise;
use Hibla\Sql\Exceptions\QueryException;

use function Hibla\asyncFn;
use function Hibla\await;

beforeAll(function (): void {
    $client = makeClient();

    await($client->query('CREATE TABLE IF NOT EXISTS mysql_client_test (
        id   INT AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(100)
    )'));

    await($client->query(
        "INSERT INTO mysql_client_test (name) VALUES ('alice'), ('bob'), ('charlie')"
    ));

    $client->close();
});

afterAll(function (): void {
    $client = makeClient();
    await($client->query('DROP TABLE IF EXISTS mysql_client_test'));
    $client->close();
});

describe('MysqlClient', function (): void {

    describe('Construction', function (): void {

        it('creates a client with a MysqlConfig instance', function (): void {
            $client = new MysqlClient(testMysqlConfig());

            try {
                expect($client->stats['statement_cache_enabled'])->toBeTrue();
            } finally {
                $client->close();
            }
        });

        it('creates a client with an array config', function (): void {
            $client = new MysqlClient([
                'host' => $_ENV['MYSQL_HOST'] ?? '127.0.0.1',
                'port' => (int) ($_ENV['MYSQL_PORT'] ?? 3306),
                'database' => $_ENV['MYSQL_DATABASE'] ?? 'test',
                'username' => $_ENV['MYSQL_USERNAME'] ?? 'test_user',
                'password' => $_ENV['MYSQL_PASSWORD'] ?? 'test_password',
            ]);

            try {
                expect($client->stats['max_size'])->toBe(10);
            } finally {
                $client->close();
            }
        });

        it('creates a client with a URI string config', function (): void {
            $host = $_ENV['MYSQL_HOST'] ?? '127.0.0.1';
            $port = $_ENV['MYSQL_PORT'] ?? '3306';
            $database = $_ENV['MYSQL_DATABASE'] ?? 'test';
            $username = $_ENV['MYSQL_USERNAME'] ?? 'test_user';
            $password = $_ENV['MYSQL_PASSWORD'] ?? 'test_password';

            $client = new MysqlClient(
                "mysql://{$username}:{$password}@{$host}:{$port}/{$database}"
            );

            try {
                expect($client->stats['max_size'])->toBe(10);
            } finally {
                $client->close();
            }
        });

        it('throws ConfigurationException for invalid maxConnections', function (): void {
            expect(fn () => makeClient(maxConnections: 0))
                ->toThrow(ConfigurationException::class)
            ;
        });

        it('throws ConfigurationException for invalid idleTimeout', function (): void {
            expect(fn () => makeClient(idleTimeout: 0))
                ->toThrow(ConfigurationException::class)
            ;
        });

        it('throws ConfigurationException for invalid maxLifetime', function (): void {
            expect(fn () => makeClient(maxLifetime: 0))
                ->toThrow(ConfigurationException::class)
            ;
        });

        it('creates a client with statement cache disabled', function (): void {
            $client = makeClient(enableStatementCache: false);

            try {
                expect($client->stats['statement_cache_enabled'])->toBeFalse();
            } finally {
                $client->close();
            }
        });
    });

    describe('Stats', function (): void {

        it('returns correct default stats before any query', function (): void {
            $client = makeClient(maxConnections: 3, statementCacheSize: 128);

            try {
                $stats = $client->stats;

                expect($stats['max_size'])->toBe(3)
                    ->and($stats['active_connections'])->toBe(0)
                    ->and($stats['pooled_connections'])->toBe(0)
                    ->and($stats['waiting_requests'])->toBe(0)
                    ->and($stats['statement_cache_enabled'])->toBeTrue()
                    ->and($stats['statement_cache_size'])->toBe(128)
                ;
            } finally {
                $client->close();
            }
        });

        it('reflects statement cache disabled in stats', function (): void {
            $client = makeClient(enableStatementCache: false);

            try {
                expect($client->stats['statement_cache_enabled'])->toBeFalse();
            } finally {
                $client->close();
            }
        });
    });

    describe('Health Check', function (): void {

        it('returns healthy stats when pool has idle connections', function (): void {
            $client = makeClient();

            try {
                await($client->query('SELECT 1'));

                $stats = await($client->healthCheck());

                expect($stats['total_checked'])->toBeGreaterThanOrEqual(1)
                    ->and($stats['unhealthy'])->toBe(0)
                ;
            } finally {
                $client->close();
            }
        });

        it('returns zero stats when no connections have been made', function (): void {
            $client = makeClient();

            try {
                $stats = await($client->healthCheck());

                expect($stats['total_checked'])->toBe(0)
                    ->and($stats['healthy'])->toBe(0)
                    ->and($stats['unhealthy'])->toBe(0)
                ;
            } finally {
                $client->close();
            }
        });
    });

    describe('Query', function (): void {

        it('executes a plain SELECT without params (text protocol)', function (): void {
            $client = makeClient();

            try {
                $result = await($client->query('SELECT 1 AS val'));
                expect($result->fetchOne()['val'])->toBe('1');
            } finally {
                $client->close();
            }
        });

        it('executes a SELECT with params (binary protocol)', function (): void {
            $client = makeClient();

            try {
                $result = await($client->query('SELECT ? AS val', [42]));
                expect($result->fetchOne()['val'])->toBe(42);
            } finally {
                $client->close();
            }
        });

        it('executes a SELECT with params and statement cache disabled', function (): void {
            $client = makeClient(enableStatementCache: false);

            try {
                $result = await($client->query('SELECT ? AS val', [99]));
                expect($result->fetchOne()['val'])->toBe(99);
            } finally {
                $client->close();
            }
        });

        it('releases connection back to pool after query', function (): void {
            $client = makeClient(maxConnections: 1);

            try {
                await($client->query('SELECT 1'));
                await($client->query('SELECT 2'));

                expect($client->stats['pooled_connections'])->toBe(1);
            } finally {
                $client->close();
            }
        });

        it('releases connection back to pool after failed query', function (): void {
            $client = makeClient(maxConnections: 1);

            try {
                try {
                    await($client->query('SELECT * FROM non_existent_table_xyz'));
                } catch (Throwable) {
                    // expected
                }

                $result = await($client->query('SELECT 1 AS val'));
                expect($result->fetchOne()['val'])->toBe('1');
            } finally {
                $client->close();
            }
        });

        it('executes multiple sequential queries correctly', function (): void {
            $client = makeClient();

            try {
                $result1 = await($client->query('SELECT 1 AS val'));
                $result2 = await($client->query('SELECT 2 AS val'));
                $result3 = await($client->query('SELECT 3 AS val'));

                expect($result1->fetchOne()['val'])->toBe('1')
                    ->and($result2->fetchOne()['val'])->toBe('2')
                    ->and($result3->fetchOne()['val'])->toBe('3')
                ;
            } finally {
                $client->close();
            }
        });
    });

    describe('Execute', function (): void {

        it('returns affected rows count after INSERT', function (): void {
            $client = makeClient();

            try {
                $affectedRows = await($client->execute(
                    "INSERT INTO mysql_client_test (name) VALUES ('execute_test')"
                ));

                expect($affectedRows)->toBe(1);

                await($client->execute(
                    "DELETE FROM mysql_client_test WHERE name = 'execute_test'"
                ));
            } finally {
                $client->close();
            }
        });

        it('returns zero affected rows when UPDATE matches no rows', function (): void {
            $client = makeClient();

            try {
                $affectedRows = await($client->execute(
                    "UPDATE mysql_client_test SET name = 'x' WHERE id = -9999"
                ));

                expect($affectedRows)->toBe(0);
            } finally {
                $client->close();
            }
        });

        it('returns affected rows with params', function (): void {
            $client = makeClient();

            try {
                $affectedRows = await($client->execute(
                    'INSERT INTO mysql_client_test (name) VALUES (?)',
                    ['execute_param_test']
                ));

                expect($affectedRows)->toBe(1);

                await($client->execute(
                    'DELETE FROM mysql_client_test WHERE name = ?',
                    ['execute_param_test']
                ));
            } finally {
                $client->close();
            }
        });
    });

    describe('ExecuteGetId', function (): void {

        it('returns the last insert id after INSERT', function (): void {
            $client = makeClient();

            try {
                $insertId = await($client->executeGetId(
                    "INSERT INTO mysql_client_test (name) VALUES ('id_test')"
                ));

                expect($insertId)->toBeGreaterThan(0);

                await($client->execute(
                    'DELETE FROM mysql_client_test WHERE id = ?',
                    [$insertId]
                ));
            } finally {
                $client->close();
            }
        });

        it('returns the last insert id with params', function (): void {
            $client = makeClient();

            try {
                $insertId = await($client->executeGetId(
                    'INSERT INTO mysql_client_test (name) VALUES (?)',
                    ['id_param_test']
                ));

                expect($insertId)->toBeGreaterThan(0);

                await($client->execute(
                    'DELETE FROM mysql_client_test WHERE id = ?',
                    [$insertId]
                ));
            } finally {
                $client->close();
            }
        });
    });

    describe('FetchOne', function (): void {

        it('returns the first row of a result set', function (): void {
            $client = makeClient();

            try {
                $row = await($client->fetchOne('SELECT 1 AS val, 2 AS other'));

                expect($row['val'])->toBe('1')
                    ->and($row['other'])->toBe('2')
                ;
            } finally {
                $client->close();
            }
        });

        it('returns null when no rows match', function (): void {
            $client = makeClient();

            try {
                $row = await($client->fetchOne('SELECT 1 WHERE 1 = 0'));
                expect($row)->toBeNull();
            } finally {
                $client->close();
            }
        });

        it('returns only the first row when multiple rows exist', function (): void {
            $client = makeClient();

            try {
                $row = await($client->fetchOne(
                    'SELECT 1 AS val UNION SELECT 2 AS val UNION SELECT 3 AS val'
                ));

                expect($row['val'])->toBe('1');
            } finally {
                $client->close();
            }
        });

        it('returns first row with params', function (): void {
            $client = makeClient();

            try {
                $row = await($client->fetchOne('SELECT ? AS val', [123]));
                expect($row['val'])->toBe(123);
            } finally {
                $client->close();
            }
        });
    });

    describe('FetchValue', function (): void {

        it('returns the value of a named column', function (): void {
            $client = makeClient();

            try {
                $value = await($client->fetchValue('SELECT 42 AS answer', 'answer'));
                expect($value)->toBe('42');
            } finally {
                $client->close();
            }
        });

        it('returns null for a numeric index because fetchOne returns associative arrays only', function (): void {
            $client = makeClient();

            try {
                $value = await($client->fetchValue('SELECT 42 AS answer', 0));
                expect($value)->toBeNull();
            } finally {
                $client->close();
            }
        });

        it('returns null when no rows match', function (): void {
            $client = makeClient();

            try {
                $value = await($client->fetchValue('SELECT 1 WHERE 1 = 0', 0));
                expect($value)->toBeNull();
            } finally {
                $client->close();
            }
        });

        it('returns null when column key does not exist', function (): void {
            $client = makeClient();

            try {
                $value = await($client->fetchValue('SELECT 1 AS val', 'nonexistent'));
                expect($value)->toBeNull();
            } finally {
                $client->close();
            }
        });

        it('returns a value with params', function (): void {
            $client = makeClient();

            try {
                $value = await($client->fetchValue('SELECT ? AS val', 'val', [99]));
                expect($value)->toBe(99);
            } finally {
                $client->close();
            }
        });
    });

    describe('Stream', function (): void {

        it('streams rows without params using text protocol', function (): void {
            $client = makeClient();

            try {
                $stream = await($client->stream('SELECT 1 AS val UNION SELECT 2 UNION SELECT 3'));

                $rows = [];
                foreach ($stream as $row) {
                    $rows[] = $row;
                }

                expect($rows)->toHaveCount(3)
                    ->and($rows[0]['val'])->toBe('1')
                ;
            } finally {
                $client->close();
            }
        });

        it('streams rows with params using binary protocol', function (): void {
            $client = makeClient();

            try {
                $stream = await($client->stream(
                    'SELECT name FROM mysql_client_test WHERE id > ? LIMIT 1',
                    [0]
                ));

                $rows = [];
                foreach ($stream as $row) {
                    $rows[] = $row;
                }

                expect($rows)->not->toBeEmpty()
                    ->and($rows[0])->toHaveKey('name')
                ;
            } finally {
                $client->close();
            }
        });

        it('releases connection back to pool after stream is fully consumed', function (): void {
            $client = makeClient(maxConnections: 1);

            try {
                $stream = await($client->stream('SELECT 1 AS val UNION SELECT 2'));

                foreach ($stream as $_) {
                    // consume
                }

                $result = await($client->query('SELECT 3 AS val'));
                expect($result->fetchOne()['val'])->toBe('3');
            } finally {
                $client->close();
            }
        });

        it('streams an empty result set without error', function (): void {
            $client = makeClient();

            try {
                $stream = await($client->stream('SELECT 1 WHERE 1 = 0'));

                $rows = [];
                foreach ($stream as $row) {
                    $rows[] = $row;
                }

                expect($rows)->toBeEmpty();
            } finally {
                $client->close();
            }
        });

        it('stream stats report correct row count', function (): void {
            $client = makeClient();

            try {
                $stream = await($client->stream(
                    'SELECT 1 AS val UNION SELECT 2 UNION SELECT 3'
                ));

                foreach ($stream as $_) {
                    // consume
                }

                expect($stream->stats->rowCount)->toBe(3);
            } finally {
                $client->close();
            }
        });
    });

    describe('Prepare', function (): void {

        it('returns a ManagedPreparedStatement', function (): void {
            $client = makeClient();

            try {
                $stmt = await($client->prepare('SELECT ? AS val'));

                try {
                    expect($stmt)->toBeInstanceOf(ManagedPreparedStatement::class);
                } finally {
                    await($stmt->close());
                }
            } finally {
                $client->close();
            }
        });

        it('executes a prepared statement and returns the correct result', function (): void {
            $client = makeClient();

            try {
                $stmt = await($client->prepare('SELECT ? AS val'));

                try {
                    $result = await($stmt->execute([42]));
                    expect($result->fetchOne()['val'])->toBe(42);
                } finally {
                    await($stmt->close());
                }
            } finally {
                $client->close();
            }
        });

        it('reuses the same prepared statement for multiple executions', function (): void {
            $client = makeClient();

            try {
                $stmt = await($client->prepare('SELECT ? AS val'));

                try {
                    $result1 = await($stmt->execute([1]));
                    $result2 = await($stmt->execute([2]));

                    expect($result1->fetchOne()['val'])->toBe(1)
                        ->and($result2->fetchOne()['val'])->toBe(2)
                    ;
                } finally {
                    await($stmt->close());
                }
            } finally {
                $client->close();
            }
        });

        it('releases connection back to pool on prepare failure', function (): void {
            $client = makeClient(maxConnections: 1);

            try {
                try {
                    await($client->prepare('NOT VALID SQL ???'));
                } catch (Throwable) {
                    // expected
                }

                $result = await($client->query('SELECT 1 AS val'));
                expect($result->fetchOne()['val'])->toBe('1');
            } finally {
                $client->close();
            }
        });
    });

    describe('Statement Cache', function (): void {

        it('reuses cached statement across multiple query() calls', function (): void {
            $client = makeClient(statementCacheSize: 10);

            try {
                $result1 = await($client->query('SELECT ? AS val', [1]));
                $result2 = await($client->query('SELECT ? AS val', [2]));

                expect($result1->fetchOne()['val'])->toBe(1)
                    ->and($result2->fetchOne()['val'])->toBe(2)
                ;
            } finally {
                $client->close();
            }
        });

        it('clearStatementCache() does not break subsequent queries', function (): void {
            $client = makeClient();

            try {
                await($client->query('SELECT ? AS val', [1]));

                $client->clearStatementCache();

                $result = await($client->query('SELECT ? AS val', [2]));
                expect($result->fetchOne()['val'])->toBe(2);
            } finally {
                $client->close();
            }
        });

        it('works correctly with cache disabled', function (): void {
            $client = makeClient(enableStatementCache: false);

            try {
                $result1 = await($client->query('SELECT ? AS val', [10]));
                $result2 = await($client->query('SELECT ? AS val', [20]));

                expect($result1->fetchOne()['val'])->toBe(10)
                    ->and($result2->fetchOne()['val'])->toBe(20)
                ;
            } finally {
                $client->close();
            }
        });

        it('clearStatementCache() is safe when cache is disabled', function (): void {
            $client = makeClient(enableStatementCache: false);

            try {
                $client->clearStatementCache();

                $result = await($client->query('SELECT 1 AS val'));
                expect($result->fetchOne()['val'])->toBe('1');
            } finally {
                $client->close();
            }
        });
    });

    describe('Close', function (): void {

        it('throws NotInitializedException after close()', function (): void {
            $client = makeClient();
            $client->close();

            expect(fn () => await($client->query('SELECT 1')))
                ->toThrow(NotInitializedException::class)
            ;
        });

        it('is safe to call close() multiple times', function (): void {
            $client = makeClient();
            $client->close();
            $client->close();

            expect(true)->toBeTrue();
        });

        it('closes cleanly even if no queries were made', function (): void {
            $client = makeClient();
            $client->close();

            expect(true)->toBeTrue();
        });
    });

    describe('Compression', function (): void {

        it('executes a plain SELECT over a compressed connection', function (): void {
            $client = makeCompressedClient();

            try {
                $result = await($client->query('SELECT 1 AS val'));
                expect($result->fetchOne()['val'])->toBe('1');
            } finally {
                $client->close();
            }
        });

        it('executes a SELECT with params over a compressed connection', function (): void {
            $client = makeCompressedClient();

            try {
                $result = await($client->query('SELECT ? AS val', [42]));
                expect($result->fetchOne()['val'])->toBe(42);
            } finally {
                $client->close();
            }
        });

        it('executes multiple sequential queries over a compressed connection', function (): void {
            $client = makeCompressedClient();

            try {
                $result1 = await($client->query('SELECT 1 AS val'));
                $result2 = await($client->query('SELECT 2 AS val'));
                $result3 = await($client->query('SELECT 3 AS val'));

                expect($result1->fetchOne()['val'])->toBe('1')
                    ->and($result2->fetchOne()['val'])->toBe('2')
                    ->and($result3->fetchOne()['val'])->toBe('3')
                ;
            } finally {
                $client->close();
            }
        });

        it('reports compression_enabled true in stats when compress is requested and server supports it', function (): void {
            $client = makeCompressedClient();

            try {
                await($client->query('SELECT 1'));
                expect($client->stats['compression_enabled'])->toBeTrue();
            } finally {
                $client->close();
            }
        });

        it('reports compression_enabled false in stats for uncompressed client', function (): void {
            $client = makeClient();

            try {
                await($client->query('SELECT 1'));
                expect($client->stats['compression_enabled'])->toBeFalse();
            } finally {
                $client->close();
            }
        });

        it('executes INSERT and DELETE over a compressed connection', function (): void {
            $client = makeCompressedClient();

            try {
                $affectedRows = await($client->execute(
                    "INSERT INTO mysql_client_test (name) VALUES ('compression_test')"
                ));

                expect($affectedRows)->toBe(1);

                $deleted = await($client->execute(
                    "DELETE FROM mysql_client_test WHERE name = 'compression_test'"
                ));

                expect($deleted)->toBe(1);
            } finally {
                $client->close();
            }
        });

        it('executes INSERT and DELETE with params over a compressed connection', function (): void {
            $client = makeCompressedClient();

            try {
                $affectedRows = await($client->execute(
                    'INSERT INTO mysql_client_test (name) VALUES (?)',
                    ['compression_param_test']
                ));

                expect($affectedRows)->toBe(1);

                $deleted = await($client->execute(
                    'DELETE FROM mysql_client_test WHERE name = ?',
                    ['compression_param_test']
                ));

                expect($deleted)->toBe(1);
            } finally {
                $client->close();
            }
        });

        it('handles large payload over a compressed connection', function (): void {
            $client = makeCompressedClient();

            try {
                $largeValue = str_repeat('ABCDEFGHIJ', 100);

                $insertId = await($client->executeGetId(
                    'INSERT INTO mysql_client_test (name) VALUES (?)',
                    [substr($largeValue, 0, 100)]
                ));

                expect($insertId)->toBeGreaterThan(0);

                await($client->execute(
                    'DELETE FROM mysql_client_test WHERE id = ?',
                    [$insertId]
                ));
            } finally {
                $client->close();
            }
        });

        it('fetches a large result set over a compressed connection', function (): void {
            $client = makeCompressedClient();

            try {
                $result = await($client->query("SHOW VARIABLES LIKE '%buffer%'"));
                expect($result->rowCount)->toBeGreaterThanOrEqual(1);
            } finally {
                $client->close();
            }
        });

        it('streams rows over a compressed connection', function (): void {
            $client = makeCompressedClient();

            try {
                $stream = await($client->stream(
                    'SELECT name FROM mysql_client_test WHERE id > ? LIMIT 3',
                    [0]
                ));

                $rows = [];
                foreach ($stream as $row) {
                    $rows[] = $row;
                }

                expect($rows)->not->toBeEmpty()
                    ->and($rows[0])->toHaveKey('name')
                ;
            } finally {
                $client->close();
            }
        });

        it('releases connection back to pool after compressed query', function (): void {
            $client = makeCompressedClient(maxConnections: 1);

            try {
                await($client->query('SELECT 1'));
                await($client->query('SELECT 2'));

                expect($client->stats['pooled_connections'])->toBe(1);
            } finally {
                $client->close();
            }
        });

        it('releases connection back to pool after failed compressed query', function (): void {
            $client = makeCompressedClient(maxConnections: 1);

            try {
                try {
                    await($client->query('SELECT * FROM non_existent_table_xyz'));
                } catch (Throwable) {
                    // expected
                }

                $result = await($client->query('SELECT 1 AS val'));
                expect($result->fetchOne()['val'])->toBe('1');
            } finally {
                $client->close();
            }
        });

        it('executes prepared statement over a compressed connection', function (): void {
            $client = makeCompressedClient();

            try {
                $stmt = await($client->prepare('SELECT ? AS val'));

                try {
                    $result = await($stmt->execute([99]));
                    expect($result->fetchOne()['val'])->toBe(99);
                } finally {
                    await($stmt->close());
                }
            } finally {
                $client->close();
            }
        });
    });

    describe('Destructor', function (): void {

        it('automatically closes when unset', function (): void {
            $client = makeClient();

            try {
                await($client->query('SELECT 1'));
                expect($client->stats['pooled_connections'])->toBeGreaterThanOrEqual(1);
            } finally {
                unset($client);
            }

            expect(true)->toBeTrue();
        });

        it('throws NotInitializedException after being garbage collected', function (): void {
            $client = makeClient();
            $client->close();

            expect(fn () => $client->stats)
                ->toThrow(NotInitializedException::class)
            ;
        });
    });

    describe('Connection Reset', function (): void {

        it('demonstrates state leakage without reset_connection', function (): void {
            $client = makeNoResetClient(maxConnections: 1);

            try {
                await($client->query("SET @leak_var = 'I_AM_A_LEAKED_VARIABLE'"));

                $result = await($client->query('SELECT @leak_var AS val'));
                expect($result->fetchOne()['val'])->toBe('I_AM_A_LEAKED_VARIABLE');
            } finally {
                $client->close();
            }
        });

        it('clears session variables between pool reuses with reset_connection enabled', function (): void {
            $client = makeResetClient(maxConnections: 1);

            try {
                await($client->query("SET @my_var = 'I_SHOULD_BE_DELETED'"));

                $result = await($client->query('SELECT @my_var AS val'));
                expect($result->fetchOne()['val'])->toBeNull();
            } finally {
                $client->close();
            }
        });

        it('clears multiple session variables between pool reuses', function (): void {
            $client = makeResetClient(maxConnections: 1);

            try {
                await($client->query('SET @a = 1, @b = 2, @c = 3'));

                $result = await($client->query('SELECT @a AS a, @b AS b, @c AS c'));
                $row = $result->fetchOne();

                expect($row['a'])->toBeNull()
                    ->and($row['b'])->toBeNull()
                    ->and($row['c'])->toBeNull()
                ;
            } finally {
                $client->close();
            }
        });

        it('handles prepared statement cache invalidation after reset', function (): void {
            $client = makeResetClient(maxConnections: 1);

            try {
                $result1 = await($client->query('SELECT ? AS num', [100]));
                expect($result1->fetchOne()['num'])->toBe(100);

                $result2 = await($client->query('SELECT ? AS num', [200]));
                expect($result2->fetchOne()['num'])->toBe(200);
            } finally {
                $client->close();
            }
        });

        it('state does not leak across multiple pool reuses', function (): void {
            $client = makeResetClient(maxConnections: 1);

            try {
                for ($i = 1; $i <= 5; $i++) {
                    await($client->query("SET @counter = {$i}"));

                    $result = await($client->query('SELECT @counter AS val'));
                    expect($result->fetchOne()['val'])->toBeNull();
                }
            } finally {
                $client->close();
            }
        });

        it('does not leak state between different logical requests on a shared pool', function (): void {
            $client = makeResetClient(maxConnections: 1);

            try {
                $tx = await($client->beginTransaction());
                await($tx->query("SET @request_id = 'request_A'"));
                $resultA = await($tx->query('SELECT @request_id AS val'));
                expect($resultA->fetchOne()['val'])->toBe('request_A');
                await($tx->commit());

                $resultB = await($client->query('SELECT @request_id AS val'));
                expect($resultB->fetchOne()['val'])->toBeNull();
            } finally {
                $client->close();
            }
        });

        it('connection remains healthy and reusable across multiple resets', function (): void {
            $client = makeResetClient(maxConnections: 1);

            try {
                for ($i = 1; $i <= 5; $i++) {
                    $result = await($client->query('SELECT ? AS val', [$i]));
                    expect($result->fetchOne()['val'])->toBe($i);
                }
            } finally {
                $client->close();
            }
        });
    });

    describe('Max Waiters', function (): void {

        it('throws PoolException immediately when waiter queue is full', function (): void {
            $maxWaiters = 3;
            $client = makeWaiterClient(maxConnections: 2, maxWaiters: $maxWaiters);

            try {
                $client->query('DO SLEEP(2)')->catch(fn () => null);
                $client->query('DO SLEEP(2)')->catch(fn () => null);

                for ($i = 0; $i < $maxWaiters; $i++) {
                    $client->query('SELECT 1')->catch(fn () => null);
                }

                expect(fn () => await($client->query("SELECT 'overflow'")))->toThrow(PoolException::class);
            } finally {
                $client->close();
            }
        });

        it('waiting_requests stat reflects queued requests up to the limit', function (): void {
            $maxWaiters = 5;
            $client = makeWaiterClient(maxConnections: 2, maxWaiters: $maxWaiters);

            try {
                $client->query('DO SLEEP(2)')->catch(fn () => null);
                $client->query('DO SLEEP(2)')->catch(fn () => null);

                for ($i = 0; $i < $maxWaiters; $i++) {
                    $client->query('SELECT 1');
                }

                expect($client->stats['waiting_requests'])->toBe($maxWaiters);
            } finally {
                $client->close();
            }
        });

        it('does not throw when requests stay within waiter limit', function (): void {
            $maxWaiters = 5;
            $client = makeWaiterClient(maxConnections: 2, maxWaiters: $maxWaiters);

            try {
                $client->query('DO SLEEP(2)')->catch(fn () => null);
                $client->query('DO SLEEP(2)')->catch(fn () => null);

                for ($i = 0; $i < $maxWaiters - 1; $i++) {
                    $client->query('SELECT 1');
                }

                expect($client->stats['waiting_requests'])->toBe($maxWaiters - 1);
            } finally {
                $client->close();
            }
        });

        it('queued requests execute successfully once a connection is released', function (): void {
            $client = makeWaiterClient(maxConnections: 1, maxWaiters: 2);

            try {
                $slow = $client->query('DO SLEEP(1)');
                $pending = $client->query('SELECT ? AS val', [42]);

                await($slow);
                $result = await($pending);

                expect($result->fetchOne()['val'])->toBe(42);
            } finally {
                $client->close();
            }
        });

        it('only the overflow request is rejected, queued requests still execute', function (): void {
            $maxWaiters = 2;
            $client = makeWaiterClient(maxConnections: 1, maxWaiters: $maxWaiters);

            try {
                $slow = $client->query('DO SLEEP(1)');

                $queued = [];
                for ($i = 0; $i < $maxWaiters; $i++) {
                    $queued[] = $client->query('SELECT ? AS val', [1]);
                }

                expect(fn () => await($client->query("SELECT 'overflow'")))->toThrow(PoolException::class);

                await($slow);

                foreach ($queued as $promise) {
                    $result = await($promise);
                    expect($result->fetchOne()['val'])->toBe(1);
                }
            } finally {
                $client->close();
            }
        });

        it('pool recovers and accepts new requests after overflow rejection', function (): void {
            $client = makeWaiterClient(maxConnections: 1, maxWaiters: 1);

            try {
                $slow = $client->query('DO SLEEP(1)');
                $queued = $client->query('SELECT ? AS val', [1]);

                try {
                    await($client->query("SELECT 'overflow'"));
                } catch (PoolException) {
                    // expected
                }

                await($slow);
                await($queued);

                $result = await($client->query('SELECT ? AS val', [99]));
                expect($result->fetchOne()['val'])->toBe(99);
            } finally {
                $client->close();
            }
        });

        it('PoolException message identifies the overflow condition', function (): void {
            $client = makeWaiterClient(maxConnections: 2, maxWaiters: 1);

            try {
                $slow1 = $client->query('DO SLEEP(1)');
                $slow2 = $client->query('DO SLEEP(1)');
                $queued = $client->query('SELECT 1');

                $exception = null;

                try {
                    await($client->query("SELECT 'overflow'"));
                } catch (PoolException $e) {
                    $exception = $e;
                }

                expect($exception)->toBeInstanceOf(PoolException::class)
                    ->and($exception->getMessage())->not->toBeEmpty()
                ;

                await($slow1);
                await($slow2);
                await($queued);
            } finally {
                $client->close();
            }
        });
    });

    describe('Acquire Timeout', function (): void {

        it('throws TimeoutException when connection cannot be acquired within the timeout', function (): void {
            $client = makeTimeoutClient(maxConnections: 1, acquireTimeout: 1.0);

            try {
                $hog = $client->query('DO SLEEP(3)');

                expect(fn () => await($client->query("SELECT 'victim'")))->toThrow(TimeoutException::class);

                await($hog);
            } finally {
                $client->close();
            }
        });

        it('times out within a reasonable window of the configured timeout', function (): void {
            $client = makeTimeoutClient(maxConnections: 1, acquireTimeout: 1.0);

            try {
                $hog = $client->query('DO SLEEP(3)');
                $start = microtime(true);

                try {
                    await($client->query("SELECT 'victim'"));
                } catch (TimeoutException) {
                    // expected
                }

                expect(microtime(true) - $start)->toBeLessThan(2.5);

                await($hog);
            } finally {
                $client->close();
            }
        });

        it('pool recovers and accepts new requests after a timeout', function (): void {
            $client = makeTimeoutClient(maxConnections: 1, acquireTimeout: 1.0);

            try {
                $hog = $client->query('DO SLEEP(2)');

                try {
                    await($client->query("SELECT 'victim'"));
                } catch (TimeoutException) {
                    // expected
                }

                await($hog);

                $result = await($client->query('SELECT ? AS val', [99]));
                expect($result->fetchOne()['val'])->toBe(99);
            } finally {
                $client->close();
            }
        });

        it('timed out request does not hold a waiting slot after expiry', function (): void {
            $client = makeTimeoutClient(maxConnections: 1, acquireTimeout: 1.0);

            try {
                $hog = $client->query('DO SLEEP(3)');

                try {
                    await($client->query("SELECT 'victim'"));
                } catch (TimeoutException) {
                    // expected
                }

                expect($client->stats['waiting_requests'])->toBe(0);

                await($hog);
            } finally {
                $client->close();
            }
        });

        it('multiple concurrent requests all timeout when pool is exhausted', function (): void {
            $client = makeTimeoutClient(maxConnections: 1, acquireTimeout: 1.0);

            try {
                $hog = $client->query('DO SLEEP(3)');

                $victims = [
                    $client->query("SELECT 'victim_1'"),
                    $client->query("SELECT 'victim_2'"),
                    $client->query("SELECT 'victim_3'"),
                ];

                $timeoutCount = 0;
                foreach ($victims as $victim) {
                    try {
                        await($victim);
                    } catch (TimeoutException) {
                        $timeoutCount++;
                    }
                }

                expect($timeoutCount)->toBe(3);

                await($hog);
            } finally {
                $client->close();
            }
        });

        it('pool is fully healthy after multiple timeouts', function (): void {
            $client = makeTimeoutClient(maxConnections: 1, acquireTimeout: 1.0);

            try {
                $hog = $client->query('DO SLEEP(2)');

                try {
                    await($client->query("SELECT 'victim_1'"));
                } catch (TimeoutException) {
                }

                try {
                    await($client->query("SELECT 'victim_2'"));
                } catch (TimeoutException) {
                }

                await($hog);

                $result1 = await($client->query('SELECT ? AS val', [1]));
                $result2 = await($client->query('SELECT ? AS val', [2]));

                expect($result1->fetchOne()['val'])->toBe(1)
                    ->and($result2->fetchOne()['val'])->toBe(2)
                    ->and($client->stats['waiting_requests'])->toBe(0)
                ;
            } finally {
                $client->close();
            }
        });
    });

    describe('Multi Statements', function (): void {

        it('rejects stacked queries when multi_statements is disabled by default', function (): void {
            $client = makeClient();

            try {
                expect(fn () => await($client->query('SELECT 1; SELECT 2')))
                    ->toThrow(QueryException::class)
                ;
            } finally {
                $client->close();
            }
        });

        it('rejects with a syntax error code when multi_statements is disabled', function (): void {
            $client = makeClient();
            $exception = null;

            try {
                try {
                    await($client->query('SELECT 1; SELECT 2'));
                } catch (QueryException $e) {
                    $exception = $e;
                }

                expect($exception)->toBeInstanceOf(QueryException::class)
                    ->and($exception->getCode())->toBe(1064)
                ;
            } finally {
                $client->close();
            }
        });

        it('executes stacked queries when multi_statements is enabled', function (): void {
            $client = makeMultiStatementClient();

            try {
                $result = await($client->query('SELECT 100 AS a; SELECT 200 AS b'));
                expect($result->fetchOne()['a'])->toBe('100');
            } finally {
                $client->close();
            }
        });

        it('provides access to the second result set via nextResult()', function (): void {
            $client = makeMultiStatementClient();

            try {
                $result1 = await($client->query('SELECT 100 AS a; SELECT 200 AS b'));
                $result2 = $result1->nextResult();

                expect($result2)->not->toBeNull()
                    ->and($result2->fetchOne()['b'])->toBe('200')
                ;
            } finally {
                $client->close();
            }
        });

        it('returns null for nextResult() when there is only one result set', function (): void {
            $client = makeMultiStatementClient();

            try {
                $result = await($client->query('SELECT 1 AS val'));
                expect($result->nextResult())->toBeNull();
            } finally {
                $client->close();
            }
        });

        it('executes three stacked queries and returns all result sets', function (): void {
            $client = makeMultiStatementClient();

            try {
                $result1 = await($client->query('SELECT 1 AS n; SELECT 2 AS n; SELECT 3 AS n'));
                $result2 = $result1->nextResult();
                $result3 = $result2?->nextResult();

                expect($result1->fetchOne()['n'])->toBe('1')
                    ->and($result2)->not->toBeNull()
                    ->and($result2->fetchOne()['n'])->toBe('2')
                    ->and($result3)->not->toBeNull()
                    ->and($result3->fetchOne()['n'])->toBe('3')
                ;
            } finally {
                $client->close();
            }
        });

        it('releases connection back to pool after multi-statement query', function (): void {
            $client = makeMultiStatementClient(maxConnections: 1);

            try {
                await($client->query('SELECT 1; SELECT 2'));
                await($client->query('SELECT 3; SELECT 4'));

                expect($client->stats['pooled_connections'])->toBe(1);
            } finally {
                $client->close();
            }
        });

        it('executes a normal single query correctly after a multi-statement query', function (): void {
            $client = makeMultiStatementClient(maxConnections: 1);

            try {
                await($client->query('SELECT 1; SELECT 2'));

                $result = await($client->query('SELECT ? AS val', [42]));
                expect($result->fetchOne()['val'])->toBe(42);
            } finally {
                $client->close();
            }
        });
    });

    describe('onConnect Hook', function (): void {

        it('fires once on first connect and sets session variable', function (): void {
            $hookCallCount = 0;

            $client = makeOnConnectClient(
                onConnect: function (ConnectionSetup $conn) use (&$hookCallCount): PromiseInterface {
                    $hookCallCount++;

                    return $conn->execute("SET SESSION time_zone = '+05:30'");
                }
            );

            try {
                $tz = await($client->fetchValue('SELECT @@session.time_zone'));

                expect($hookCallCount)->toBe(1)
                    ->and($tz)->toBe('+05:30')
                ;
            } finally {
                $client->close();
            }
        });

        it('does not fire again on connection reuse', function (): void {
            $hookCallCount = 0;

            $client = makeOnConnectClient(
                onConnect: function (ConnectionSetup $conn) use (&$hookCallCount): PromiseInterface {
                    $hookCallCount++;

                    return $conn->execute("SET SESSION time_zone = '+05:30'");
                }
            );

            try {
                await($client->fetchValue('SELECT @@session.time_zone'));
                await($client->fetchValue('SELECT @@session.time_zone'));

                expect($hookCallCount)->toBe(1);
            } finally {
                $client->close();
            }
        });

        it('session variable persists across checkouts on the same connection', function (): void {
            $client = makeOnConnectClient(
                onConnect: fn (ConnectionSetup $conn) => $conn->execute("SET SESSION time_zone = '+05:30'")
            );

            try {
                $tz1 = await($client->fetchValue('SELECT @@session.time_zone'));
                $tz2 = await($client->fetchValue('SELECT @@session.time_zone'));

                expect($tz1)->toBe('+05:30')
                    ->and($tz2)->toBe('+05:30')
                ;
            } finally {
                $client->close();
            }
        });

        it('hook receives ConnectionSetup, not the internal Connection', function (): void {
            $receivedClass = null;

            $client = makeOnConnectClient(
                onConnect: function (mixed $conn) use (&$receivedClass): void {
                    $receivedClass = get_class($conn);
                }
            );

            try {
                await($client->fetchValue('SELECT 1'));

                expect($receivedClass)
                    ->toBe(Hibla\Mysql\Internals\ConnectionSetup::class)
                    ->and(is_a($receivedClass, ConnectionSetup::class, true))->toBeTrue()
                ;
            } finally {
                $client->close();
            }
        });

        it('async hook is fully awaited before the query runs', function (): void {
            $order = [];

            $client = makeOnConnectClient(
                onConnect: function (ConnectionSetup $conn) use (&$order): PromiseInterface {
                    return $conn->execute("SET SESSION time_zone = '+00:00'")
                        ->then(function () use (&$order): void {
                            $order[] = 'hook_done';
                        })
                    ;
                }
            );

            try {
                await($client->fetchValue('SELECT @@session.time_zone'));
                $order[] = 'query_done';

                expect($order)->toBe(['hook_done', 'query_done']);
            } finally {
                $client->close();
            }
        });

        it('fires once per physical connection when pool grows', function (): void {
            $hookCallCount = 0;

            $client = makeOnConnectClient(
                maxConnections: 3,
                onConnect: function (ConnectionSetup $conn) use (&$hookCallCount): PromiseInterface {
                    $hookCallCount++;

                    return $conn->execute("SET SESSION time_zone = '+09:00'");
                }
            );

            try {
                [$tz1, $tz2, $tz3] = await(Promise::all([
                    $client->fetchValue('SELECT @@session.time_zone'),
                    $client->fetchValue('SELECT @@session.time_zone'),
                    $client->fetchValue('SELECT @@session.time_zone'),
                ]));

                expect($hookCallCount)->toBe(3)
                    ->and($tz1)->toBe('+09:00')
                    ->and($tz2)->toBe('+09:00')
                    ->and($tz3)->toBe('+09:00')
                ;

                await(Promise::all([
                    $client->fetchValue('SELECT 1'),
                    $client->fetchValue('SELECT 1'),
                    $client->fetchValue('SELECT 1'),
                ]));

                expect($hookCallCount)->toBe(3);
            } finally {
                $client->close();
            }
        });

        it('rejects the caller and drops the connection when hook fails', function (): void {
            $client = makeOnConnectClient(
                onConnect: fn (ConnectionSetup $conn) => $conn->execute(
                    'SET SESSION invalid_var_that_does_not_exist = 1'
                )
            );

            try {
                expect(fn () => await($client->fetchValue('SELECT 1')))
                    ->toThrow(QueryException::class)
                ;

                expect($client->stats['active_connections'])->toBe(0);
            } finally {
                $client->close();
            }
        });

        it('query() in hook returns a MysqlResult the hook can inspect', function (): void {
            $serverVersion = null;

            $client = makeOnConnectClient(
                onConnect: function (ConnectionSetup $conn) use (&$serverVersion): PromiseInterface {
                    return $conn->query('SELECT VERSION() AS version')
                        ->then(function (Hibla\Mysql\Interfaces\MysqlResult $result) use (&$serverVersion): void {
                            $row = $result->fetchOne();
                            $serverVersion = $row['version'] ?? null;
                        })
                    ;
                }
            );

            try {
                await($client->fetchValue('SELECT 1'));
                expect($serverVersion)->not->toBeNull();
            } finally {
                $client->close();
            }
        });

        it('no hook — normal pool behaviour is unchanged', function (): void {
            $client = makeOnConnectClient();

            try {
                $result = await($client->fetchValue('SELECT 42'));

                expect((int) $result)->toBe(42)
                    ->and($client->stats['on_connect_hook'])->toBeFalse()
                ;
            } finally {
                $client->close();
            }
        });

        it('hook re-runs after COM_RESET_CONNECTION restores session state', function (): void {
            $hookCallCount = 0;

            $client = makeOnConnectClient(
                resetConnection: true,
                onConnect: function (ConnectionSetup $conn) use (&$hookCallCount): PromiseInterface {
                    $hookCallCount++;

                    return $conn->execute("SET SESSION time_zone = '+05:30'");
                }
            );

            try {
                $tz1 = await($client->fetchValue('SELECT @@session.time_zone'));

                expect($hookCallCount)->toBe(1)
                    ->and($tz1)->toBe('+05:30')
                ;

                $tz2 = await($client->fetchValue('SELECT @@session.time_zone'));

                expect($hookCallCount)->toBe(2)
                    ->and($tz2)->toBe('+05:30')
                ;

                $tz3 = await($client->fetchValue('SELECT @@session.time_zone'));

                expect($hookCallCount)->toBe(3)
                    ->and($tz3)->toBe('+05:30')
                ;
            } finally {
                $client->close();
            }
        });

        it('asyncFn pattern works as onConnect hook', function (): void {
            $hookCallCount = 0;

            $client = makeOnConnectClient(
                onConnect: asyncFn(function (ConnectionSetup $conn) use (&$hookCallCount): void {
                    $hookCallCount++;
                    await($conn->execute("SET SESSION time_zone = '+05:30'"));
                    await($conn->execute("SET SESSION sql_mode = 'STRICT_ALL_TABLES'"));
                })
            );

            try {
                $tz = await($client->fetchValue('SELECT @@session.time_zone'));
                $mode = await($client->fetchValue('SELECT @@session.sql_mode'));

                expect($hookCallCount)->toBe(1)
                    ->and($tz)->toBe('+05:30')
                    ->and($mode)->toBe('STRICT_ALL_TABLES')
                ;

                await($client->fetchValue('SELECT 1'));

                expect($hookCallCount)->toBe(1);
            } finally {
                $client->close();
            }
        });
    });
});
