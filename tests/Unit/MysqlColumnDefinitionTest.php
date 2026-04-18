<?php

declare(strict_types=1);

namespace Hibla\Mysql\Tests\ValueObjects;

use Hibla\Mysql\ValueObjects\MysqlColumnDefinition;
use Rcalicdan\MySQLBinaryProtocol\Constants\CharsetIdentifiers;
use Rcalicdan\MySQLBinaryProtocol\Constants\ColumnFlags;
use Rcalicdan\MySQLBinaryProtocol\Constants\MysqlType;

describe('MysqlColumnDefinition', function (): void {

    it('maps basic metadata correctly', function (): void {
        $raw = createRawCol([
            'catalog' => 'def',
            'schema' => 'test_db',
            'table' => 'u',
            'orgTable' => 'users',
            'name' => 'uid',
            'orgName' => 'id',
        ]);

        $col = new MysqlColumnDefinition($raw);

        expect($col->catalog)->toBe('def')
            ->and($col->schema)->toBe('test_db')
            ->and($col->table)->toBe('u')
            ->and($col->orgTable)->toBe('users')
            ->and($col->name)->toBe('uid')
            ->and($col->orgName)->toBe('id');
    });

    describe('Type Resolution', function (): void {
        it('resolves integer types and handles signed/unsigned', function (): void {
            $signed = new MysqlColumnDefinition(createRawCol(['type' => MysqlType::LONG, 'flags' => 0]));
            expect($signed->typeName)->toBe('INT');

            $unsigned = new MysqlColumnDefinition(createRawCol([
                'type' => MysqlType::LONG,
                'flags' => ColumnFlags::UNSIGNED_FLAG
            ]));

            expect($unsigned->typeName)->toBe('INT UNSIGNED');

            $tiny = new MysqlColumnDefinition(createRawCol([
                'type' => MysqlType::TINY,
                'flags' => ColumnFlags::UNSIGNED_FLAG
            ]));

            expect($tiny->typeName)->toBe('TINYINT UNSIGNED');
        });

        it('resolves string types correctly (CHAR vs ENUM vs SET)', function (): void {
            $char = new MysqlColumnDefinition(createRawCol(['type' => MysqlType::STRING, 'flags' => 0]));
            expect($char->typeName)->toBe('CHAR');

            $enum = new MysqlColumnDefinition(createRawCol(['type' => MysqlType::STRING, 'flags' => 0x100]));
            expect($enum->typeName)->toBe('ENUM');

            $set = new MysqlColumnDefinition(createRawCol(['type' => MysqlType::STRING, 'flags' => 0x800]));
            expect($set->typeName)->toBe('SET');
        });

        it('resolves BLOB variants', function (): void {
            $blob = new MysqlColumnDefinition(createRawCol(['type' => MysqlType::BLOB]));
            expect($blob->typeName)->toBe('BLOB');

            $tinyBlob = new MysqlColumnDefinition(createRawCol(['type' => MysqlType::TINY_BLOB]));
            expect($tinyBlob->typeName)->toBe('TINYBLOB');
        });
    });

    describe('Charset and Length Resolution', function (): void {
        it('resolves charset names', function (): void {
            expect((new MysqlColumnDefinition(createRawCol(['charset' => CharsetIdentifiers::UTF8MB4])))->charsetName)->toBe('utf8mb4');
            expect((new MysqlColumnDefinition(createRawCol(['charset' => CharsetIdentifiers::UTF8])))->charsetName)->toBe('utf8');
            expect((new MysqlColumnDefinition(createRawCol(['charset' => CharsetIdentifiers::LATIN1])))->charsetName)->toBe('latin1');
            expect((new MysqlColumnDefinition(createRawCol(['charset' => 63])))->charsetName)->toBe('binary');
        });

        it('calculates character length based on bytes-per-character', function (): void {
            $utf8mb4 = new MysqlColumnDefinition(createRawCol([
                'charset' => CharsetIdentifiers::UTF8MB4,
                'columnLength' => 40
            ]));

            expect($utf8mb4->length)->toBe(10);

            $latin1 = new MysqlColumnDefinition(createRawCol([
                'charset' => CharsetIdentifiers::LATIN1,
                'columnLength' => 40
            ]));

            expect($latin1->length)->toBe(40);
        });
    });

    describe('Boolean Property Helpers', function (): void {
        it('detects nullability', function (): void {
            $nullable = new MysqlColumnDefinition(createRawCol(['flags' => 0]));
            expect($nullable->isNullable())->toBeTrue();

            $notNull = new MysqlColumnDefinition(createRawCol(['flags' => ColumnFlags::NOT_NULL_FLAG]));
            expect($notNull->isNullable())->toBeFalse();
        });

        it('detects primary and unique keys', function (): void {
            $pk = new MysqlColumnDefinition(createRawCol(['flags' => ColumnFlags::PRI_KEY_FLAG]));
            expect($pk->isPrimaryKey())->toBeTrue();

            $unique = new MysqlColumnDefinition(createRawCol(['flags' => ColumnFlags::UNIQUE_KEY_FLAG]));
            expect($unique->isUniqueKey())->toBeTrue();
        });

        it('detects auto_increment', function (): void {
            $ai = new MysqlColumnDefinition(createRawCol(['flags' => 0x200]));
            expect($ai->isAutoIncrement())->toBeTrue();
        });

        it('detects binary and blobs', function (): void {
            $bin = new MysqlColumnDefinition(createRawCol(['flags' => ColumnFlags::BINARY_FLAG]));
            expect($bin->isBinary())->toBeTrue();

            $blob = new MysqlColumnDefinition(createRawCol(['flags' => ColumnFlags::BLOB_FLAG]));
            expect($blob->isBlob())->toBeTrue();
        });
    });

    it('resolves a human-readable list of flags', function (): void {
        $raw = createRawCol([
            'flags' => ColumnFlags::NOT_NULL_FLAG | ColumnFlags::PRI_KEY_FLAG | ColumnFlags::UNSIGNED_FLAG | 0x200
        ]);

        $col = new MysqlColumnDefinition($raw);

        expect($col->resolvedFlags)->toContain('NOT NULL')
            ->and($col->resolvedFlags)->toContain('PRIMARY KEY')
            ->and($col->resolvedFlags)->toContain('UNSIGNED')
            ->and($col->resolvedFlags)->toContain('AUTO INCREMENT');
    });
});
