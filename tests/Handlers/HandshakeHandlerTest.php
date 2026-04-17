<?php

declare(strict_types=1);

use Hibla\EventLoop\Loop;
use Hibla\Mysql\Handlers\HandshakeHandler;
use Hibla\Promise\Interfaces\PromiseInterface;
use Hibla\Promise\Promise;
use Hibla\Socket\Interfaces\ConnectionInterface as SocketConnection;
use Rcalicdan\MySQLBinaryProtocol\Factory\DefaultPacketReaderFactory;
use Rcalicdan\MySQLBinaryProtocol\Packet\PayloadReader;

use function Hibla\await;

describe('HandshakeHandler', function () {
    it('creates handshake handler successfully', function () {
        $socket = Mockery::mock(SocketConnection::class);
        $params = createMysqlConfig();

        $handler = new HandshakeHandler($socket, $params);

        expect($handler)->toBeInstanceOf(HandshakeHandler::class);
    });

    it('start returns a promise', function () {
        $socket = Mockery::mock(SocketConnection::class);
        $params = createMysqlConfig();
        $handler = new HandshakeHandler($socket, $params);

        $packetReader = (new DefaultPacketReaderFactory())->createWithDefaultSettings();

        $result = $handler->start($packetReader);

        expect($result)->toBeInstanceOf(PromiseInterface::class);
    });

    it('rejects when server does not support SSL', function () {
        $params = createMysqlConfig(ssl: true);
        $socket = Mockery::mock(SocketConnection::class);

        $handler = new HandshakeHandler($socket, $params);

        $handshakePacket = buildMySQLHandshakeV10Packet(supportsSSL: false);
        $packetReader = (new DefaultPacketReaderFactory())->createWithDefaultSettings();
        $packetReader->append($handshakePacket);

        $promise = $handler->start($packetReader);

        $exception = null;

        try {
            await($promise);
        } catch (Throwable $e) {
            $exception = $e;
        }

        expect($exception)->not->toBeNull()
            ->and($exception->getMessage())->toContain('server does not support SSL')
        ;
    });

    it('writes SSL request packet when SSL is enabled', function () {
        $params = createMysqlConfig(ssl: true);

        $socket = Mockery::mock(SocketConnection::class);

        $socket->shouldReceive('write')->twice()->andReturnUsing(function ($packet) {
            expect(strlen($packet))->toBeGreaterThan(0);

            return true;
        });

        $socket->shouldReceive('enableEncryption')
            ->once()
            ->andReturn(Promise::resolved())
        ;

        $handler = new HandshakeHandler($socket, $params);

        $handshakePacket = buildMySQLHandshakeV10Packet(supportsSSL: true);
        $packetReader = (new DefaultPacketReaderFactory())->createWithDefaultSettings();
        $packetReader->append($handshakePacket);

        $handler->start($packetReader);

        Loop::run();
    });

    it('sends auth response without SSL when SSL is disabled', function () {
        $params = createMysqlConfig(ssl: false);

        $socket = Mockery::mock(SocketConnection::class);
        $socket->shouldReceive('write')->once();

        $handler = new HandshakeHandler($socket, $params);

        $handshakePacket = buildMySQLHandshakeV10Packet(supportsSSL: false);
        $packetReader = (new DefaultPacketReaderFactory())->createWithDefaultSettings();
        $packetReader->append($handshakePacket);

        $handler->start($packetReader);

        expect(true)->toBeTrue();
    });

    it('resolves promise on successful authentication with OK packet', function () {
        $params = createMysqlConfig();
        $socket = Mockery::mock(SocketConnection::class);
        $socket->shouldReceive('write')->once();

        $handler = new HandshakeHandler($socket, $params);

        $handshakePacket = buildMySQLHandshakeV10Packet();
        $packetReader = (new DefaultPacketReaderFactory())->createWithDefaultSettings();
        $packetReader->append($handshakePacket);

        $promise = $handler->start($packetReader);

        $payloadReader = Mockery::mock(PayloadReader::class);
        $payloadReader->shouldReceive('readFixedInteger')->with(1)->andReturn(0x00);
        $payloadReader->shouldReceive('readLengthEncodedIntegerOrNull')->andReturn(0);
        $payloadReader->shouldReceive('readFixedInteger')->with(2)->andReturn(0);
        $payloadReader->shouldReceive('readRestOfPacketString')->andReturn('');

        $handler->processPacket($payloadReader, 7, 2);

        $resolved = false;
        $promise->then(function ($sequenceId) use (&$resolved) {
            $resolved = true;
            expect($sequenceId)->toBe(3);
        });

        Loop::run();

        expect($resolved)->toBeTrue();
    });

    it('rejects promise on authentication error with ERR packet', function () {
        $params = createMysqlConfig();
        $socket = Mockery::mock(SocketConnection::class);
        $socket->shouldReceive('write')->once();

        $handler = new HandshakeHandler($socket, $params);

        $handshakePacket = buildMySQLHandshakeV10Packet();
        $packetReader = (new DefaultPacketReaderFactory())->createWithDefaultSettings();
        $packetReader->append($handshakePacket);

        $promise = $handler->start($packetReader);

        $payloadReader = Mockery::mock(PayloadReader::class);
        $payloadReader->shouldReceive('readFixedInteger')->with(1)->andReturn(0xFF);
        $payloadReader->shouldReceive('readFixedInteger')->with(2)->andReturn(1045);
        $payloadReader->shouldReceive('readFixedString')->with(1)->andReturn('#');
        $payloadReader->shouldReceive('readFixedString')->with(5)->andReturn('28000');
        $payloadReader->shouldReceive('readRestOfPacketString')->andReturn('Access denied');

        $handler->processPacket($payloadReader, 50, 2);

        $rejected = false;
        $errorMessage = '';

        $promise->catch(function ($e) use (&$rejected, &$errorMessage) {
            $rejected = true;
            $errorMessage = $e->getMessage();
        });

        Loop::run();

        expect($rejected)->toBeTrue()
            ->and($errorMessage)->toContain('MySQL Authentication Error')
            ->and($errorMessage)->toContain('1045')
        ;
    });

    it('handles auth switch request', function () {
        $params = createMysqlConfig();
        $socket = Mockery::mock(SocketConnection::class);
        $socket->shouldReceive('write')->twice();

        $handler = new HandshakeHandler($socket, $params);

        $handshakePacket = buildMySQLHandshakeV10Packet();
        $packetReader = (new DefaultPacketReaderFactory())->createWithDefaultSettings();
        $packetReader->append($handshakePacket);

        $handler->start($packetReader);

        $payloadReader = Mockery::mock(PayloadReader::class);
        $payloadReader->shouldReceive('readFixedInteger')->with(1)->andReturn(0xFE);
        $payloadReader->shouldReceive('readNullTerminatedString')->andReturn('mysql_native_password');
        $payloadReader->shouldReceive('readRestOfPacketString')->andReturn('scramble');

        $handler->processPacket($payloadReader, 30, 2);

        expect(true)->toBeTrue();
    });

    it('handles fast auth success status', function () {
        $params = createMysqlConfig();
        $socket = Mockery::mock(SocketConnection::class);
        $socket->shouldReceive('write')->once();

        $handler = new HandshakeHandler($socket, $params);

        $handshakePacket = buildMySQLHandshakeV10Packet();
        $packetReader = (new DefaultPacketReaderFactory())->createWithDefaultSettings();
        $packetReader->append($handshakePacket);

        $handler->start($packetReader);

        $payloadReader = Mockery::mock(PayloadReader::class);
        $payloadReader->shouldReceive('readFixedInteger')->with(1)->andReturn(0x01);
        $payloadReader->shouldReceive('readRestOfPacketString')->andReturn("\x03");

        $handler->processPacket($payloadReader, 2, 2);

        expect(true)->toBeTrue();
    });

    it('handles full auth required over SSL', function () {
        $params = createMysqlConfig(ssl: true);

        $socket = Mockery::mock(SocketConnection::class);

        $socket->shouldReceive('write')->times(3);
        $socket->shouldReceive('enableEncryption')->once()->andReturn(Promise::resolved());

        $handler = new HandshakeHandler($socket, $params);

        $handshakePacket = buildMySQLHandshakeV10Packet(supportsSSL: true);
        $packetReader = (new DefaultPacketReaderFactory())->createWithDefaultSettings();
        $packetReader->append($handshakePacket);

        $handler->start($packetReader);

        Loop::run();

        $payloadReader = Mockery::mock(PayloadReader::class);
        $payloadReader->shouldReceive('readFixedInteger')->with(1)->andReturn(0x01);
        $payloadReader->shouldReceive('readRestOfPacketString')->andReturn("\x04");

        $handler->processPacket($payloadReader, 2, 2);

        expect(true)->toBeTrue();
    });
});
