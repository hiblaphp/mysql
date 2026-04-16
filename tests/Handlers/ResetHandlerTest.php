<?php

declare(strict_types=1);

namespace Hibla\Mysql\Tests\Handlers;

use Hibla\EventLoop\Loop;
use Hibla\Mysql\Handlers\ResetHandler;
use Hibla\Mysql\Internals\Connection;
use Hibla\Promise\Promise;
use Mockery;
use Rcalicdan\MySQLBinaryProtocol\Packet\PayloadReader;

describe('ResetHandler', function () {
    it('creates reset handler successfully', function () {
        $connection = Mockery::mock(Connection::class);

        $handler = new ResetHandler($connection);

        expect($handler)->toBeInstanceOf(ResetHandler::class);
    });

    it('starts reset and writes COM_RESET_CONNECTION packet to socket', function () {
        $connection = Mockery::mock(Connection::class);
        $connection->shouldReceive('writePacket')->once()->andReturnUsing(function ($payload, $seq) {
            expect(ord($payload[0]))->toBe(0x1F);
            expect($seq)->toBe(0);
        });

        $handler = new ResetHandler($connection);
        /** @var Promise<bool> $promise */
        $promise = new Promise();

        $handler->start($promise);

        expect(true)->toBeTrue();
    });

    it('resolves promise with true on OK packet', function () {
        $connection = Mockery::mock(Connection::class);
        $connection->shouldReceive('writePacket')->once();

        $handler = new ResetHandler($connection);
        /** @var Promise<bool> $promise */
        $promise = new Promise();

        $handler->start($promise);

        $payloadReader = Mockery::mock(PayloadReader::class);
        $payloadReader->shouldReceive('readFixedInteger')->with(1)->andReturn(0x00);
        $payloadReader->shouldReceive('readLengthEncodedIntegerOrNull')->andReturn(0, 0, 0);
        $payloadReader->shouldReceive('readFixedInteger')->with(2)->andReturn(0);
        $payloadReader->shouldReceive('readRestOfPacketString')->andReturn('');

        $handler->processPacket($payloadReader, 7, 1);

        $result = null;
        $promise->then(function ($r) use (&$result) {
            $result = $r;
        });

        Loop::run();

        expect($result)->toBeTrue();
    });

    it('rejects promise on ERR packet', function () {
        $connection = Mockery::mock(Connection::class);
        $connection->shouldReceive('writePacket')->once();

        $handler = new ResetHandler($connection);
        /** @var Promise<bool> $promise */
        $promise = new Promise();

        $handler->start($promise);

        $payloadReader = Mockery::mock(PayloadReader::class);
        $payloadReader->shouldReceive('readFixedInteger')->with(1)->andReturn(0xFF);
        $payloadReader->shouldReceive('readFixedInteger')->with(2)->andReturn(1053);
        $payloadReader->shouldReceive('readFixedString')->with(1)->andReturn('#');
        $payloadReader->shouldReceive('readFixedString')->with(5)->andReturn('08S01');
        $payloadReader->shouldReceive('readRestOfPacketString')->andReturn('Server shutdown in progress');

        $handler->processPacket($payloadReader, 40, 1);

        $errorMessage = '';
        $promise->catch(function ($e) use (&$errorMessage) {
            $errorMessage = $e->getMessage();
        });

        Loop::run();

        expect($errorMessage)
            ->toContain('MySQL Reset Connection Error')
            ->and($errorMessage)->toContain('Server shutdown in progress')
        ;
    });

    it('rejects promise on unexpected packet type', function () {
        $connection = Mockery::mock(Connection::class);
        $connection->shouldReceive('writePacket')->once();

        $handler = new ResetHandler($connection);
        /** @var Promise<bool> $promise */
        $promise = new Promise();

        $handler->start($promise);

        $payloadReader = Mockery::mock(PayloadReader::class);
        $payloadReader->shouldReceive('readFixedInteger')->with(1)->andReturn(0x01);
        $payloadReader->shouldReceive('readLengthEncodedIntegerOrNull')->andReturn(1);
        $payloadReader->shouldReceive('readRestOfPacketString')->andReturn('');

        $handler->processPacket($payloadReader, 5, 1);

        $errorMessage = '';
        $promise->catch(function ($e) use (&$errorMessage) {
            $errorMessage = $e->getMessage();
        });

        Loop::run();

        expect($errorMessage)->toContain('Unexpected packet type in reset response');
    });

    it('rejects promise when processPacket throws', function () {
        $connection = Mockery::mock(Connection::class);
        $connection->shouldReceive('writePacket')->once();

        $handler = new ResetHandler($connection);
        /** @var Promise<bool> $promise */
        $promise = new Promise();

        $handler->start($promise);

        $payloadReader = Mockery::mock(PayloadReader::class);
        $payloadReader->shouldReceive('readFixedInteger')->with(1)->andThrow(new \Exception('Malformed packet'));

        $handler->processPacket($payloadReader, 1, 1);

        $errorMessage = '';
        $promise->catch(function ($e) use (&$errorMessage) {
            $errorMessage = $e->getMessage();
        });

        Loop::run();

        expect($errorMessage)->toContain('Malformed packet');
    });

    it('wraps non-ConnectionException into ConnectionException', function () {
        $connection = Mockery::mock(Connection::class);
        $connection->shouldReceive('writePacket')->once();

        $handler = new ResetHandler($connection);
        /** @var Promise<bool> $promise */
        $promise = new Promise();

        $handler->start($promise);

        $payloadReader = Mockery::mock(PayloadReader::class);
        $payloadReader->shouldReceive('readFixedInteger')->with(1)->andThrow(new \RuntimeException('Raw runtime error', 42));

        $handler->processPacket($payloadReader, 1, 1);

        $caughtException = null;
        $promise->catch(function ($e) use (&$caughtException) {
            $caughtException = $e;
        });

        Loop::run();

        expect($caughtException)
            ->toBeInstanceOf(\Hibla\Sql\Exceptions\ConnectionException::class)
            ->and($caughtException->getMessage())->toContain('Failed to process reset response')
            ->and($caughtException->getMessage())->toContain('Raw runtime error')
            ->and($caughtException->getCode())->toBe(42)
        ;
    });
});
