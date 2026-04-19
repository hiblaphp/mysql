<?php

declare(strict_types=1);

use Hibla\Mysql\Internals\RowStream;

use function Hibla\await;

beforeAll(function (): void {
    $conn = makeConnection();
    await($conn->query('CREATE TABLE IF NOT EXISTS conn_named_test (
        id INT PRIMARY KEY, 
        name VARCHAR(50), 
        age INT
    )'));
    await($conn->query("INSERT INTO conn_named_test (id, name, age) VALUES (1, 'Alice', 30), (2, 'Bob', 25)"));
    $conn->close();
});

afterAll(function (): void {
    $conn = makeConnection();
    await($conn->query('DROP TABLE IF EXISTS conn_named_test'));
    $conn->close();
});

describe('Connection Named Parameters', function (): void {

    it('executes a prepared statement using named parameters', function (): void {
        $conn = makeConnection();
        $stmt = await($conn->prepare('SELECT * FROM conn_named_test WHERE name = :name AND age = :age'));

        $result = await($stmt->execute(['name' => 'Alice', 'age' => 30]));
        $row = $result->fetchOne();

        expect($result->rowCount)->toBe(1)
            ->and($row['id'])->toBe(1)
            ->and($row['name'])->toBe('Alice')
        ;

        await($stmt->close());
        $conn->close();
    });

    it('handles multiple occurrences of the same named parameter', function (): void {
        $conn = makeConnection();
        $stmt = await($conn->prepare('SELECT * FROM conn_named_test WHERE name = :target OR :target = "Alice" ORDER BY id DESC LIMIT 1'));

        $result = await($stmt->execute(['target' => 'Bob']));
        $row = $result->fetchOne();

        expect($row['id'])->toBe(2)
            ->and($row['name'])->toBe('Bob')
        ;

        await($stmt->close());
        $conn->close();
    });

    it('executes a streaming query using named parameters', function (): void {
        $conn = makeConnection();
        $stmt = await($conn->prepare('SELECT * FROM conn_named_test WHERE age >= :min_age ORDER BY id'));

        $stream = await($stmt->executeStream(['min_age' => 20]));
        expect($stream)->toBeInstanceOf(RowStream::class);

        $rows = [];
        foreach ($stream as $row) {
            $rows[] = $row;
        }

        expect($rows)->toHaveCount(2)
            ->and($rows[0]['name'])->toBe('Alice')
            ->and($rows[1]['name'])->toBe('Bob')
        ;

        await($stmt->close());
        $conn->close();
    });

    it('throws InvalidArgumentException when a required named parameter is missing', function (): void {
        $conn = makeConnection();
        $stmt = await($conn->prepare('SELECT * FROM conn_named_test WHERE name = :name'));

        expect(fn () => await($stmt->execute(['wrong_key' => 'Alice'])))
            ->toThrow(InvalidArgumentException::class, 'Missing value for named parameter: :name')
        ;

        await($stmt->close());
        $conn->close();
    });

    it('ignores extra parameters provided in the array', function (): void {
        $conn = makeConnection();
        $stmt = await($conn->prepare('SELECT * FROM conn_named_test WHERE id = :id'));

        $result = await($stmt->execute(['id' => 2, 'extra_param' => 'ignored']));
        $row = $result->fetchOne();

        expect($row['name'])->toBe('Bob');

        await($stmt->close());
        $conn->close();
    });

    it('throws InvalidArgumentException when parsing mixed positional and named parameters', function (): void {
        $conn = makeConnection();

        expect(fn () => await($conn->prepare('SELECT * FROM conn_named_test WHERE id = ? AND name = :name')))
            ->toThrow(InvalidArgumentException::class, 'Cannot mix named and positional parameters')
        ;

        $conn->close();
    });
});
