<?php

declare(strict_types=1);

namespace Hibla\Mysql\Tests\Handlers;

use Hibla\EventLoop\Loop;
use Hibla\Mysql\Handlers\PrepareHandler;
use Hibla\Mysql\Internals\Connection as MysqlConnection;
use Hibla\Mysql\Internals\PreparedStatement;
use Hibla\Promise\Promise;
use Mockery;
use Rcalicdan\MySQLBinaryProtocol\Frame\Command\CommandBuilder;
use Rcalicdan\MySQLBinaryProtocol\Packet\PayloadReader;

describe('PrepareHandler', function () {
    it('creates prepare handler successfully', function () {
        $connection = Mockery::mock(MysqlConnection::class);
        $commandBuilder = new CommandBuilder();

        $handler = new PrepareHandler($connection, $commandBuilder);

        expect($handler)->toBeInstanceOf(PrepareHandler::class);
    });

    it('starts prepare operation and writes packet to socket', function () {
        $connection = Mockery::mock(MysqlConnection::class);
        $connection->shouldReceive('writePacket')->once()->andReturnUsing(function ($packet, $seq) {
            expect(strlen($packet))->toBeGreaterThan(0);
            expect($seq)->toBe(0);
        });

        $commandBuilder = new CommandBuilder();
        $handler = new PrepareHandler($connection, $commandBuilder);
        $promise = new Promise();

        $handler->start('SELECT * FROM users WHERE id = ?', $promise);

        expect(true)->toBeTrue();
    });

    it('rejects promise on ERR packet', function () {
        $connection = Mockery::mock(MysqlConnection::class);
        $connection->shouldReceive('writePacket')->once();

        $commandBuilder = new CommandBuilder();
        $handler = new PrepareHandler($connection, $commandBuilder);
        $promise = new Promise();

        $handler->start('SELECT * FROM invalid_table', $promise);

        $payloadReader = Mockery::mock(PayloadReader::class);
        $payloadReader->shouldReceive('readFixedInteger')->with(1)->andReturn(0xFF);
        $payloadReader->shouldReceive('readFixedInteger')->with(2)->andReturn(1064);
        $payloadReader->shouldReceive('readFixedString')->with(1)->andReturn('#');
        $payloadReader->shouldReceive('readFixedString')->with(5)->andReturn('42000');
        $payloadReader->shouldReceive('readRestOfPacketString')->andReturn('You have an error in your SQL syntax');

        $result = $handler->processPacket($payloadReader, 50, 0);

        $rejected = false;
        $errorMessage = '';

        $promise->catch(function ($e) use (&$rejected, &$errorMessage) {
            $rejected = true;
            $errorMessage = $e->getMessage();
        });

        Loop::run();

        expect($rejected)->toBeTrue()
            ->and($errorMessage)->toContain('Failed to prepare statement')
            ->and($errorMessage)->toContain('1064')
            ->and($result)->toBeTrue()
        ;
    });

    it('handles successful prepare with no params and no columns', function () {
        $connection = Mockery::mock(MysqlConnection::class);
        $connection->shouldReceive('writePacket')->once();
        // PreparedStatement destructor will call this
        $connection->shouldReceive('closeStatement')->with(123)->andReturn(Promise::resolved());

        $commandBuilder = new CommandBuilder();
        $handler = new PrepareHandler($connection, $commandBuilder);
        $promise = new Promise();

        $handler->start('SET @var = 1', $promise);

        $payloadReader = Mockery::mock(PayloadReader::class);
        $payloadReader->shouldReceive('readFixedInteger')->with(1)->andReturn(0x00);
        $payloadReader->shouldReceive('readFixedInteger')->with(4)->andReturn(123);
        $payloadReader->shouldReceive('readFixedInteger')->with(2)->andReturn(0, 0);
        $payloadReader->shouldReceive('readFixedInteger')->with(1)->andReturn(0);
        $payloadReader->shouldReceive('readFixedInteger')->with(2)->andReturn(0);

        $result = $handler->processPacket($payloadReader, 12, 0);

        $resolved = false;
        $stmt = null;

        $promise->then(function ($s) use (&$resolved, &$stmt) {
            $resolved = true;
            $stmt = $s;
        });

        Loop::run();

        expect($resolved)->toBeTrue()
            ->and($stmt)->toBeInstanceOf(PreparedStatement::class)
            ->and($stmt->id)->toBe(123)
            ->and($stmt->numParams)->toBe(0)
            ->and($stmt->numColumns)->toBe(0)
            ->and($result)->toBeTrue()
        ;
    });

    it('handles successful prepare with params only', function () {
        $connection = Mockery::mock(MysqlConnection::class);
        $connection->shouldReceive('writePacket')->once();
        $connection->shouldReceive('closeStatement')->with(124)->andReturn(Promise::resolved());

        $commandBuilder = new CommandBuilder();
        $handler = new PrepareHandler($connection, $commandBuilder);
        $promise = new Promise();

        $handler->start('INSERT INTO users (name) VALUES (?)', $promise);

        $headerReader = Mockery::mock(PayloadReader::class);
        $headerReader->shouldReceive('readFixedInteger')->with(1)->andReturn(0x00);
        $headerReader->shouldReceive('readFixedInteger')->with(4)->andReturn(124);
        $headerReader->shouldReceive('readFixedInteger')->with(2)->andReturn(0, 1);
        $headerReader->shouldReceive('readFixedInteger')->with(1)->andReturn(0);
        $headerReader->shouldReceive('readFixedInteger')->with(2)->andReturn(0);

        $result1 = $handler->processPacket($headerReader, 12, 0);
        expect($result1)->toBeFalse();

        $paramReader = Mockery::mock(PayloadReader::class);
        // Combined readFixedInteger(1) expectations: Header (0x03), Type (253), Decimals (0)
        $paramReader->shouldReceive('readFixedInteger')->with(1)->andReturn(0x03, 253, 0);
        $paramReader->shouldReceive('readFixedString')->with(3)->andReturn('def');
        $paramReader->shouldReceive('readLengthEncodedStringOrNull')->andReturn('test', 'users', 'users', 'name', 'name');
        $paramReader->shouldReceive('readLengthEncodedIntegerOrNull')->andReturn(null);
        $paramReader->shouldReceive('readFixedInteger')->with(2)->andReturn(33, 0, 0);
        $paramReader->shouldReceive('readFixedInteger')->with(4)->andReturn(255);

        $result2 = $handler->processPacket($paramReader, 30, 1);
        expect($result2)->toBeFalse();

        $eofReader = Mockery::mock(PayloadReader::class);
        $eofReader->shouldReceive('readFixedInteger')->with(1)->andReturn(0xFE);
        $eofReader->shouldReceive('readFixedInteger')->with(2)->andReturn(0, 0);

        $result3 = $handler->processPacket($eofReader, 5, 2);

        $resolved = false;
        $stmt = null;

        $promise->then(function ($s) use (&$resolved, &$stmt) {
            $resolved = true;
            $stmt = $s;
        });

        Loop::run();

        expect($resolved)->toBeTrue()
            ->and($stmt)->toBeInstanceOf(PreparedStatement::class)
            ->and($stmt->id)->toBe(124)
            ->and($stmt->numParams)->toBe(1)
            ->and($stmt->numColumns)->toBe(0)
            ->and($result3)->toBeTrue()
        ;
    });

    it('handles successful prepare with columns only', function () {
        $connection = Mockery::mock(MysqlConnection::class);
        $connection->shouldReceive('writePacket')->once();
        $connection->shouldReceive('closeStatement')->with(125)->andReturn(Promise::resolved());

        $commandBuilder = new CommandBuilder();
        $handler = new PrepareHandler($connection, $commandBuilder);
        $promise = new Promise();

        $handler->start('SELECT id, name FROM users', $promise);

        $headerReader = Mockery::mock(PayloadReader::class);
        $headerReader->shouldReceive('readFixedInteger')->with(1)->andReturn(0x00);
        $headerReader->shouldReceive('readFixedInteger')->with(4)->andReturn(125);
        $headerReader->shouldReceive('readFixedInteger')->with(2)->andReturn(2, 0);
        $headerReader->shouldReceive('readFixedInteger')->with(1)->andReturn(0);
        $headerReader->shouldReceive('readFixedInteger')->with(2)->andReturn(0);

        $result1 = $handler->processPacket($headerReader, 12, 0);
        expect($result1)->toBeFalse();

        // Col 1
        $col1Reader = Mockery::mock(PayloadReader::class);
        $col1Reader->shouldReceive('readFixedInteger')->with(1)->andReturn(0x03, 3, 0);
        $col1Reader->shouldReceive('readFixedString')->with(3)->andReturn('def');
        $col1Reader->shouldReceive('readLengthEncodedStringOrNull')->andReturn('test', 'users', 'users', 'id', 'id');
        $col1Reader->shouldReceive('readLengthEncodedIntegerOrNull')->andReturn(null);
        $col1Reader->shouldReceive('readFixedInteger')->with(2)->andReturn(63, 16899, 0);
        $col1Reader->shouldReceive('readFixedInteger')->with(4)->andReturn(11);

        $result2 = $handler->processPacket($col1Reader, 30, 1);
        expect($result2)->toBeFalse();

        // Col 2
        $col2Reader = Mockery::mock(PayloadReader::class);
        $col2Reader->shouldReceive('readFixedInteger')->with(1)->andReturn(0x03, 253, 0);
        $col2Reader->shouldReceive('readFixedString')->with(3)->andReturn('def');
        $col2Reader->shouldReceive('readLengthEncodedStringOrNull')->andReturn('test', 'users', 'users', 'name', 'name');
        $col2Reader->shouldReceive('readLengthEncodedIntegerOrNull')->andReturn(null);
        $col2Reader->shouldReceive('readFixedInteger')->with(2)->andReturn(33, 0, 0);
        $col2Reader->shouldReceive('readFixedInteger')->with(4)->andReturn(255);

        $result3 = $handler->processPacket($col2Reader, 30, 2);
        expect($result3)->toBeFalse();

        $eofReader = Mockery::mock(PayloadReader::class);
        $eofReader->shouldReceive('readFixedInteger')->with(1)->andReturn(0xFE);
        $eofReader->shouldReceive('readFixedInteger')->with(2)->andReturn(0, 0);

        $result4 = $handler->processPacket($eofReader, 5, 3);

        $resolved = false;
        $stmt = null;

        $promise->then(function ($s) use (&$resolved, &$stmt) {
            $resolved = true;
            $stmt = $s;
        });

        Loop::run();

        expect($resolved)->toBeTrue()
            ->and($stmt)->toBeInstanceOf(PreparedStatement::class)
            ->and($stmt->id)->toBe(125)
            ->and($stmt->numParams)->toBe(0)
            ->and($stmt->numColumns)->toBe(2)
            ->and($result4)->toBeTrue()
        ;
    });

    it('handles successful prepare with both params and columns', function () {
        $connection = Mockery::mock(MysqlConnection::class);
        $connection->shouldReceive('writePacket')->once();
        $connection->shouldReceive('closeStatement')->with(126)->andReturn(Promise::resolved());

        $commandBuilder = new CommandBuilder();
        $handler = new PrepareHandler($connection, $commandBuilder);
        $promise = new Promise();

        $handler->start('SELECT id, name FROM users WHERE id = ?', $promise);

        $headerReader = Mockery::mock(PayloadReader::class);
        $headerReader->shouldReceive('readFixedInteger')->with(1)->andReturn(0x00);
        $headerReader->shouldReceive('readFixedInteger')->with(4)->andReturn(126);
        $headerReader->shouldReceive('readFixedInteger')->with(2)->andReturn(2, 1);
        $headerReader->shouldReceive('readFixedInteger')->with(1)->andReturn(0);
        $headerReader->shouldReceive('readFixedInteger')->with(2)->andReturn(0);

        $result1 = $handler->processPacket($headerReader, 12, 0);
        expect($result1)->toBeFalse();

        // Param 1
        $paramReader = Mockery::mock(PayloadReader::class);
        $paramReader->shouldReceive('readFixedInteger')->with(1)->andReturn(0x03, 3, 0);
        $paramReader->shouldReceive('readFixedString')->with(3)->andReturn('def');
        $paramReader->shouldReceive('readLengthEncodedStringOrNull')->andReturn('', '', '', '?', '?');
        $paramReader->shouldReceive('readLengthEncodedIntegerOrNull')->andReturn(null);
        $paramReader->shouldReceive('readFixedInteger')->with(2)->andReturn(63, 128, 0);
        $paramReader->shouldReceive('readFixedInteger')->with(4)->andReturn(11);

        $result2 = $handler->processPacket($paramReader, 30, 1);
        expect($result2)->toBeFalse();

        // EOF params
        $paramEofReader = Mockery::mock(PayloadReader::class);
        $paramEofReader->shouldReceive('readFixedInteger')->with(1)->andReturn(0xFE);
        $paramEofReader->shouldReceive('readFixedInteger')->with(2)->andReturn(0, 0);

        $result3 = $handler->processPacket($paramEofReader, 5, 2);
        expect($result3)->toBeFalse();

        // Col 1
        $col1Reader = Mockery::mock(PayloadReader::class);
        $col1Reader->shouldReceive('readFixedInteger')->with(1)->andReturn(0x03, 3, 0);
        $col1Reader->shouldReceive('readFixedString')->with(3)->andReturn('def');
        $col1Reader->shouldReceive('readLengthEncodedStringOrNull')->andReturn('test', 'users', 'users', 'id', 'id');
        $col1Reader->shouldReceive('readLengthEncodedIntegerOrNull')->andReturn(null);
        $col1Reader->shouldReceive('readFixedInteger')->with(2)->andReturn(63, 16899, 0);
        $col1Reader->shouldReceive('readFixedInteger')->with(4)->andReturn(11);

        $result4 = $handler->processPacket($col1Reader, 30, 3);
        expect($result4)->toBeFalse();

        // Col 2
        $col2Reader = Mockery::mock(PayloadReader::class);
        $col2Reader->shouldReceive('readFixedInteger')->with(1)->andReturn(0x03, 253, 0);
        $col2Reader->shouldReceive('readFixedString')->with(3)->andReturn('def');
        $col2Reader->shouldReceive('readLengthEncodedStringOrNull')->andReturn('test', 'users', 'users', 'name', 'name');
        $col2Reader->shouldReceive('readLengthEncodedIntegerOrNull')->andReturn(null);
        $col2Reader->shouldReceive('readFixedInteger')->with(2)->andReturn(33, 0, 0);
        $col2Reader->shouldReceive('readFixedInteger')->with(4)->andReturn(255);

        $result5 = $handler->processPacket($col2Reader, 30, 4);
        expect($result5)->toBeFalse();

        // EOF cols
        $colEofReader = Mockery::mock(PayloadReader::class);
        $colEofReader->shouldReceive('readFixedInteger')->with(1)->andReturn(0xFE);
        $colEofReader->shouldReceive('readFixedInteger')->with(2)->andReturn(0, 0);

        $result6 = $handler->processPacket($colEofReader, 5, 5);

        $resolved = false;
        $stmt = null;

        $promise->then(function ($s) use (&$resolved, &$stmt) {
            $resolved = true;
            $stmt = $s;
        });

        Loop::run();

        expect($resolved)->toBeTrue()
            ->and($stmt)->toBeInstanceOf(PreparedStatement::class)
            ->and($stmt->id)->toBe(126)
            ->and($stmt->numParams)->toBe(1)
            ->and($stmt->numColumns)->toBe(2)
            ->and($result6)->toBeTrue()
        ;
    });

    it('handles unexpected packet in header state', function () {
        $connection = Mockery::mock(MysqlConnection::class);
        $connection->shouldReceive('writePacket')->once();

        $commandBuilder = new CommandBuilder();
        $handler = new PrepareHandler($connection, $commandBuilder);
        $promise = new Promise();

        $handler->start('SELECT 1', $promise);

        $payloadReader = Mockery::mock(PayloadReader::class);
        $payloadReader->shouldReceive('readFixedInteger')->with(1)->andReturn(0x01); // Not 0x00 (OK) or 0xFF (ERR)

        $handler->processPacket($payloadReader, 10, 0);

        $rejected = false;
        $errorMessage = '';

        $promise->catch(function ($e) use (&$rejected, &$errorMessage) {
            $rejected = true;
            $errorMessage = $e->getMessage();
        });

        Loop::run();

        expect($rejected)->toBeTrue()
            ->and($errorMessage)->toContain('Unexpected packet type in prepare response')
        ;
    });
});
