<?php

declare(strict_types=1);

use Hibla\Promise\Promise;
use Hibla\Sql\Exceptions\ConstraintViolationException;
use Hibla\Sql\Exceptions\QueryException;

use function Hibla\async;
use function Hibla\await;

beforeAll(function (): void {
    $client = makeClient();

    // 1. Simple no params
    await($client->query('DROP PROCEDURE IF EXISTS sp_no_params'));
    await($client->query('
        CREATE PROCEDURE sp_no_params()
        BEGIN
            SELECT "hello" AS greeting;
        END
    '));

    // 2. Simple IN params
    await($client->query('DROP PROCEDURE IF EXISTS sp_in_params'));
    await($client->query('
        CREATE PROCEDURE sp_in_params(IN p_val INT)
        BEGIN
            SELECT p_val * 2 AS result;
        END
    '));

    // 3. Simple OUT params
    await($client->query('DROP PROCEDURE IF EXISTS sp_out_params'));
    await($client->query('
        CREATE PROCEDURE sp_out_params(OUT p_out INT)
        BEGIN
            SET p_out = 500;
        END
    '));

    // 4. Multiple Result Sets
    await($client->query('DROP PROCEDURE IF EXISTS sp_multi_results'));
    await($client->query('
        CREATE PROCEDURE sp_multi_results()
        BEGIN
            SELECT 10 AS first;
            SELECT 20 AS second;
            SELECT 30 AS third;
        END
    '));

    // 5. Basic DML & Setup Table
    await($client->query('DROP TABLE IF EXISTS sp_test_table'));
    await($client->query('CREATE TABLE sp_test_table (id INT AUTO_INCREMENT PRIMARY KEY, val VARCHAR(50))'));
    await($client->query('DROP PROCEDURE IF EXISTS sp_insert_row'));
    await($client->query('
        CREATE PROCEDURE sp_insert_row(IN p_name VARCHAR(50))
        BEGIN
            INSERT INTO sp_test_table (val) VALUES (p_name);
            SELECT LAST_INSERT_ID() AS new_id;
        END
    '));

    // 6. Mixed DML and Select
    await($client->query('DROP PROCEDURE IF EXISTS sp_mixed_dml_select'));
    await($client->query('
        CREATE PROCEDURE sp_mixed_dml_select(IN p_val VARCHAR(50))
        BEGIN
            INSERT INTO sp_test_table (val) VALUES (p_val);
            SELECT val FROM sp_test_table WHERE val = p_val ORDER BY id DESC LIMIT 1;
        END
    '));

    // 7. Only DML
    await($client->query('DROP PROCEDURE IF EXISTS sp_only_dml'));
    await($client->query('
        CREATE PROCEDURE sp_only_dml(IN p_old VARCHAR(50), IN p_new VARCHAR(50))
        BEGIN
            UPDATE sp_test_table SET val = p_new WHERE val = p_old;
        END
    '));

    // 8. Error Mid-Execution
    await($client->query('DROP TABLE IF EXISTS sp_error_table'));
    await($client->query('CREATE TABLE sp_error_table (id INT PRIMARY KEY)'));
    await($client->query('DROP PROCEDURE IF EXISTS sp_error_mid_exec'));
    await($client->query('
        CREATE PROCEDURE sp_error_mid_exec()
        BEGIN
            SELECT "working" AS status;
            INSERT INTO sp_error_table (id) VALUES (1);
            INSERT INTO sp_error_table (id) VALUES (1); -- Will throw duplicate entry error
            SELECT "should not reach here" AS status;
        END
    '));

    // 9. Multiple OUT params
    await($client->query('DROP PROCEDURE IF EXISTS sp_multi_out'));
    await($client->query('
        CREATE PROCEDURE sp_multi_out(IN p_in INT, OUT p_out1 INT, OUT p_out2 INT)
        BEGIN
            SET p_out1 = p_in * 10;
            SET p_out2 = p_in * 100;
        END
    '));

    // 10. Empty Result Sets
    await($client->query('DROP PROCEDURE IF EXISTS sp_empty_results'));
    await($client->query('
        CREATE PROCEDURE sp_empty_results()
        BEGIN
            SELECT 1 AS val WHERE 1=0;
            SELECT 2 AS val;
            SELECT 3 AS val WHERE 1=0;
        END
    '));

    // 11. Large Data for Streaming
    await($client->query('DROP TABLE IF EXISTS sp_large_table'));
    await($client->query('CREATE TABLE sp_large_table (id INT AUTO_INCREMENT PRIMARY KEY, num INT)'));
    await($client->query('DROP PROCEDURE IF EXISTS sp_large_result'));
    await($client->query('
        CREATE PROCEDURE sp_large_result()
        BEGIN
            DECLARE i INT DEFAULT 1;
            
            -- Ensure the table is empty before we populate it, 
            -- so we always get exactly 50 rows even on repeated calls.
            TRUNCATE TABLE sp_large_table;
            
            WHILE i <= 50 DO
                INSERT INTO sp_large_table (num) VALUES (i);
                SET i = i + 1;
            END WHILE;
            
            SELECT num FROM sp_large_table ORDER BY id ASC;
        END
    '));

    // Call the large result prep immediately so it populates the table
    await($client->query('CALL sp_large_result()'));

    // 12. Many Result Sets (Stress test traversal)
    await($client->query('DROP PROCEDURE IF EXISTS sp_many_results'));
    await($client->query('
        CREATE PROCEDURE sp_many_results()
        BEGIN
            SELECT 1; SELECT 2; SELECT 3; SELECT 4; SELECT 5;
        END
    '));

    $client->close();
});

afterAll(function (): void {
    $client = makeClient();
    await($client->query('DROP PROCEDURE IF EXISTS sp_no_params'));
    await($client->query('DROP PROCEDURE IF EXISTS sp_in_params'));
    await($client->query('DROP PROCEDURE IF EXISTS sp_out_params'));
    await($client->query('DROP PROCEDURE IF EXISTS sp_multi_results'));
    await($client->query('DROP PROCEDURE IF EXISTS sp_insert_row'));
    await($client->query('DROP PROCEDURE IF EXISTS sp_mixed_dml_select'));
    await($client->query('DROP PROCEDURE IF EXISTS sp_only_dml'));
    await($client->query('DROP PROCEDURE IF EXISTS sp_error_mid_exec'));
    await($client->query('DROP PROCEDURE IF EXISTS sp_multi_out'));
    await($client->query('DROP PROCEDURE IF EXISTS sp_empty_results'));
    await($client->query('DROP PROCEDURE IF EXISTS sp_large_result'));
    await($client->query('DROP PROCEDURE IF EXISTS sp_many_results'));
    await($client->query('DROP TABLE IF EXISTS sp_test_table'));
    await($client->query('DROP TABLE IF EXISTS sp_error_table'));
    await($client->query('DROP TABLE IF EXISTS sp_large_table'));
    $client->close();
});

describe('Stored Procedures', function (): void {

    it('executes a simple procedure with no parameters (Text Protocol)', function (): void {
        $client = makeClient();
        $result = await($client->query('CALL sp_no_params()'));
        expect($result->fetchOne()['greeting'])->toBe('hello');
        $client->close();
    });

    it('executes a procedure with IN parameters (Binary Protocol)', function (): void {
        $client = makeClient();
        $result = await($client->query('CALL sp_in_params(?)', [21]));
        expect((int)$result->fetchOne()['result'])->toBe(42);
        $client->close();
    });

    it('handles OUT parameters via user variables', function (): void {
        $client = makeClient();
        await($client->query('CALL sp_out_params(@my_out)'));
        $result = await($client->query('SELECT @my_out AS val'));
        expect((int)$result->fetchOne()['val'])->toBe(500);
        $client->close();
    });

    it('traverses multiple result sets returned by a procedure', function (): void {
        $client = makeClient();
        $result = await($client->query('CALL sp_multi_results()'));
        expect((int)$result->fetchOne()['first'])->toBe(10);

        $result = $result->nextResult();
        expect($result)->not->toBeNull();
        expect((int)$result->fetchOne()['second'])->toBe(20);

        $result = $result->nextResult();
        expect($result)->not->toBeNull();
        expect((int)$result->fetchOne()['third'])->toBe(30);

        expect($result->nextResult())->toBeNull();
        $client->close();
    });

    it('handles DML operations inside a procedure', function (): void {
        $client = makeClient();
        $result = await($client->query('CALL sp_insert_row(?)', ['test_val']));
        $newId = (int)$result->fetchOne()['new_id'];
        expect($newId)->toBeGreaterThan(0);

        $check = await($client->query('SELECT val FROM sp_test_table WHERE id = ?', [$newId]));
        expect($check->fetchOne()['val'])->toBe('test_val');
        $client->close();
    });

    it('remains in a healthy state if multiple results are NOT fully traversed', function (): void {
        $client = makeClient(maxConnections: 1);
        await($client->query('CALL sp_multi_results()'));

        $result = await($client->query('SELECT "clean" AS status'));
        expect($result->fetchOne()['status'])->toBe('clean');
        $client->close();
    });

    it('throws QueryException when procedure does not exist', function (): void {
        $client = makeClient();
        expect(fn () => await($client->query('CALL procedure_that_does_not_exist()')))
            ->toThrow(QueryException::class)
        ;
        $client->close();
    });

    it('streams the first result set of a procedure', function (): void {
        $client = makeClient();
        $stream = await($client->stream('CALL sp_multi_results()'));

        $rows = [];
        foreach ($stream as $row) {
            $rows[] = $row;
        }

        expect($rows)->toHaveCount(1)
            ->and((int)$rows[0]['first'])->toBe(10)
        ;
        $client->close();
    });

    // --- NEW ROBUSTNESS TESTS --- //

    it('handles mixed DML and SELECT result sets sequentially', function (): void {
        $client = makeClient();
        $result = await($client->query('CALL sp_mixed_dml_select(?)', ['mixed_test']));

        // SP returns the SELECT output as the primary result set, MySQL abstracts away the raw DML OkPacket internally
        expect($result->fetchOne()['val'])->toBe('mixed_test');
        $client->close();
    });

    it('handles procedures containing exclusively DML statements', function (): void {
        $client = makeClient();

        // Setup initial row
        await($client->query('INSERT INTO sp_test_table (val) VALUES ("old_val")'));

        // Execute the SP
        $affected = await($client->execute('CALL sp_only_dml(?, ?)', ['old_val', 'new_val']));

        // SP DML does not map 1:1 to statement affected rows natively unless CLIENT_MULTI_RESULTS parses it,
        // but we verify the actual data change below to ensure the call was successful.
        $check = await($client->query('SELECT COUNT(*) as c FROM sp_test_table WHERE val = "new_val"'));
        expect((int)$check->fetchOne()['c'])->toBeGreaterThanOrEqual(1);

        $client->close();
    });

    it('handles fatal errors mid-execution without protocol desync', function (): void {
        $client = makeClient(maxConnections: 1); // Force same connection

        // The SP will output one result set, then crash on the duplicate INSERT
        $promise = $client->query('CALL sp_error_mid_exec()');

        expect(fn () => await($promise))->toThrow(ConstraintViolationException::class);

        // Ensure the connection is not permanently corrupted or desynced
        $result = await($client->query('SELECT 1 AS alive'));
        expect((int)$result->fetchOne()['alive'])->toBe(1);

        $client->close();
    });

    it('handles multiple OUT parameters simultaneously', function (): void {
        $client = makeClient();

        await($client->query('CALL sp_multi_out(?, @out1, @out2)', [5]));
        $result = await($client->query('SELECT @out1 AS o1, @out2 AS o2'));

        $row = $result->fetchOne();
        expect((int)$row['o1'])->toBe(50)
            ->and((int)$row['o2'])->toBe(500)
        ;

        $client->close();
    });

    it('keeps the PreparedStatement cache stable when calling SPs in a loop', function (): void {
        $client = makeClient();

        for ($i = 1; $i <= 5; $i++) {
            $result = await($client->query('CALL sp_in_params(?)', [$i]));
            expect((int)$result->fetchOne()['result'])->toBe($i * 2);
        }

        $client->close();
    });

    it('traverses gracefully over empty result sets', function (): void {
        $client = makeClient();

        $result = await($client->query('CALL sp_empty_results()'));

        // First result set is empty WHERE 1=0
        expect($result->rowCount)->toBe(0);

        // Second result set has data
        $result = $result->nextResult();
        expect($result)->not->toBeNull()
            ->and((int)$result->fetchOne()['val'])->toBe(2)
        ;

        // Third result set is empty
        $result = $result->nextResult();
        expect($result)->not->toBeNull()
            ->and($result->rowCount)->toBe(0)
        ;

        expect($result->nextResult())->toBeNull();

        $client->close();
    });

    it('streams a larger result set from a procedure seamlessly', function (): void {
        $client = makeClient();
        $stream = await($client->stream('CALL sp_large_result()', bufferSize: 10));

        $count = 0;
        foreach ($stream as $row) {
            $count++;
            expect((int)$row['num'])->toBe($count);
        }

        expect($count)->toBe(50);
        $client->close();
    });

    it('integrates seamlessly inside transactions and supports rollback', function (): void {
        $client = makeClient();

        await($client->transaction(function ($tx) {
            await($tx->query('CALL sp_insert_row(?)', ['tx_rollback_test']));

            throw new RuntimeException('Trigger Rollback');
        }, new Hibla\Sql\TransactionOptions(attempts: 1))->catch(fn () => null));

        $check = await($client->query('SELECT COUNT(*) as c FROM sp_test_table WHERE val = "tx_rollback_test"'));
        expect((int)$check->fetchOne()['c'])->toBe(0);

        $client->close();
    });

    it('traverses an exceptionally large chain of result sets', function (): void {
        $client = makeClient();

        $result = await($client->query('CALL sp_many_results()'));
        $count = 0;

        while ($result !== null) {
            $count++;
            $val = (int)array_values($result->fetchOne())[0];
            expect($val)->toBe($count);
            $result = $result->nextResult();
        }

        expect($count)->toBe(5);

        $client->close();
    });

    it('multiplexes concurrent SP calls accurately on the connection pool', function (): void {
        $client = makeClient(minConnections: 5, maxConnections: 5);

        $promises = [];
        for ($i = 1; $i <= 10; $i++) {
            $promises[] = async(function () use ($client, $i) {
                $result = await($client->query('CALL sp_in_params(?)', [$i]));

                return (int)$result->fetchOne()['result'];
            });
        }

        $results = await(Promise::all($promises));

        foreach ($results as $index => $val) {
            $expected = ($index + 1) * 2;
            expect($val)->toBe($expected);
        }

        $client->close();
    });
});
