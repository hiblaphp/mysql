<?php

declare(strict_types=1);

namespace Hibla\Mysql\Handlers;

use Hibla\EventLoop\Loop;
use Hibla\Mysql\ValueObjects\MysqlConfig;
use Hibla\Promise\Interfaces\PromiseInterface;
use Hibla\Promise\Promise;
use Hibla\Socket\Interfaces\ConnectionInterface as SocketConnection;
use Hibla\Sql\Exceptions\AuthenticationException;
use Hibla\Sql\Exceptions\ConnectionException;
use Rcalicdan\MySQLBinaryProtocol\Auth\AuthScrambler;
use Rcalicdan\MySQLBinaryProtocol\Constants\CapabilityFlags;
use Rcalicdan\MySQLBinaryProtocol\Constants\CharsetMap;
use Rcalicdan\MySQLBinaryProtocol\Frame\Handshake\AuthMoreData;
use Rcalicdan\MySQLBinaryProtocol\Frame\Handshake\AuthResponseParser;
use Rcalicdan\MySQLBinaryProtocol\Frame\Handshake\AuthSwitchRequest;
use Rcalicdan\MySQLBinaryProtocol\Frame\Handshake\HandshakeParser;
use Rcalicdan\MySQLBinaryProtocol\Frame\Handshake\HandshakeResponse41;
use Rcalicdan\MySQLBinaryProtocol\Frame\Handshake\HandshakeV10;
use Rcalicdan\MySQLBinaryProtocol\Frame\Handshake\SslRequest;
use Rcalicdan\MySQLBinaryProtocol\Frame\Response\ErrPacket;
use Rcalicdan\MySQLBinaryProtocol\Frame\Response\OkPacket;
use Rcalicdan\MySQLBinaryProtocol\Packet\PayloadReader;
use Rcalicdan\MySQLBinaryProtocol\Packet\UncompressedPacketReader;
use Rcalicdan\MySQLBinaryProtocol\Packet\UncompressedPacketWriter;

/**
 * Handles MySQL handshake protocol including SSL/TLS upgrade and Compression negotiation.
 *
 * @internal
 */
final class HandshakeHandler
{
    private string $scramble = '';

    private string $authPlugin = '';

    private int $serverCapabilities = 0;

    private int $sequenceId = 0;

    private bool $isSslEnabled = false;

    private int $threadId = 0;

    private bool $compressionNegotiated = false;

    /**
     * @var Promise<int>
     */
    private Promise $promise;

    private UncompressedPacketWriter $packetWriter;

    public function __construct(
        private readonly SocketConnection $socket,
        private readonly MysqlConfig $params
    ) {
        /** @var Promise<int> $promise */
        $promise = new Promise();
        $this->promise = $promise;
        $this->packetWriter = new UncompressedPacketWriter();
    }

    /**
     * Returns the MySQL connection/thread ID received during handshake.
     * Returns 0 if handshake has not completed yet.
     */
    public function getThreadId(): int
    {
        return $this->threadId;
    }

    /**
     * Returns whether the CLIENT_COMPRESS capability was agreed upon.
     */
    public function isCompressionEnabled(): bool
    {
        return $this->compressionNegotiated;
    }

    /**
     * Starts the handshake process.
     *
     * @return PromiseInterface<int>
     */
    public function start(UncompressedPacketReader $packetReader): PromiseInterface
    {
        if ($packetReader->hasPacket()) {
            $packetReader->readPayload($this->handleInitialHandshake(...));
        }

        return $this->promise;
    }

    /**
     * Processes incoming packets during the handshake phase.
     */
    public function processPacket(PayloadReader $payloadReader, int $length, int $seq): void
    {
        if ($this->serverCapabilities === 0) {
            $this->handleInitialHandshake($payloadReader, $length, $seq);

            return;
        }

        $this->handleAuthResponse($payloadReader, $length, $seq);
    }

    private function handleInitialHandshake(PayloadReader $reader, int $length, int $seq): void
    {
        try {
            $frame = new HandshakeParser()->parse($reader, $length, $seq);

            if ($frame instanceof ErrPacket) {
                $this->promise->reject(new ConnectionException("MySQL Connection Error [{$frame->errorCode}]: {$frame->errorMessage}", $frame->errorCode));

                return;
            }

            /** @var HandshakeV10 $handshake */
            $handshake = $frame;

            $this->threadId = $handshake->connectionId;
            $this->scramble = $handshake->authData;
            $this->authPlugin = $handshake->authPlugin;
            $this->serverCapabilities = $handshake->capabilities;
            $this->sequenceId = $seq + 1;

            $clientCaps = $this->calculateCapabilities();
            $charsetId = CharsetMap::getCollationId($this->params->charset);

            if (($clientCaps & CapabilityFlags::CLIENT_COMPRESS) !== 0) {
                $this->compressionNegotiated = true;
            }

            if ($this->params->useSsl() && ($this->serverCapabilities & CapabilityFlags::CLIENT_SSL) !== 0) {
                $this->performSslUpgrade($clientCaps, $charsetId);
            } else {
                if ($this->params->useSsl() && ($this->serverCapabilities & CapabilityFlags::CLIENT_SSL) === 0) {
                    $this->promise->reject(new ConnectionException('SSL/TLS connection requested but server does not support SSL', 0));

                    return;
                }

                $this->sendAuthResponse($clientCaps, $charsetId);
            }
        } catch (\Throwable $e) {
            $this->promise->reject(new ConnectionException('Failed to process initial handshake: ' . $e->getMessage(), (int)$e->getCode(), $e));
        }
    }

    private function performSslUpgrade(int $clientCaps, int $charsetId): void
    {
        try {
            $payload = new SslRequest()->build($clientCaps, $charsetId);
            $this->writePacket($payload);

            Loop::setImmediate(function () use ($clientCaps, $charsetId) {
                $this->configureSslAndEnable($clientCaps, $charsetId);
            });
        } catch (\Throwable $e) {
            $this->promise->reject(new ConnectionException('Failed to initiate SSL upgrade: ' . $e->getMessage(), (int)$e->getCode(), $e));
        }
    }

    private function configureSslAndEnable(int $clientCaps, int $charsetId): void
    {
        try {
            $sslOptions = [
                'verify_peer' => $this->params->sslVerify,
                'verify_peer_name' => $this->params->sslVerify,
                'allow_self_signed' => ! $this->params->sslVerify,
                'crypto_method' => STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT | STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT,
            ];

            if ($this->params->sslCa !== null) {
                $sslOptions['cafile'] = $this->params->sslCa;
            }
            if ($this->params->sslCert !== null) {
                $sslOptions['local_cert'] = $this->params->sslCert;
            }
            if ($this->params->sslKey !== null) {
                $sslOptions['local_pk'] = $this->params->sslKey;
            }

            /** @var PromiseInterface<mixed> $encryptionPromise */
            $encryptionPromise = $this->socket->enableEncryption($sslOptions);

            $encryptionPromise->then(
                function () use ($clientCaps, $charsetId): void {
                    $this->isSslEnabled = true;
                    $this->sendAuthResponse($clientCaps, $charsetId);
                },
                function (\Throwable $e): void {
                    $this->promise->reject(new ConnectionException('SSL/TLS handshake failed: ' . $e->getMessage(), 0, $e));
                }
            );
        } catch (\Throwable $e) {
            $this->promise->reject(new ConnectionException('Failed to configure SSL/TLS options: ' . $e->getMessage(), (int)$e->getCode(), $e));
        }
    }

    private function sendAuthResponse(int $clientCaps, int $charsetId): void
    {
        try {
            $response = (new HandshakeResponse41())->build(
                $clientCaps,
                $charsetId,
                $this->params->username,
                $this->generateAuthResponse($this->authPlugin, $this->scramble),
                $this->params->database,
                $this->authPlugin
            );
            $this->writePacket($response);
        } catch (\Throwable $e) {
            $this->promise->reject(new AuthenticationException('Failed to build authentication response: ' . $e->getMessage(), (int)$e->getCode(), $e));
        }
    }

    private function handleAuthResponse(PayloadReader $reader, int $length, int $seq): void
    {
        try {
            $frame = new AuthResponseParser()->parse($reader, $length, $seq);
            $this->sequenceId = $seq + 1;

            if ($frame instanceof OkPacket) {
                $this->promise->resolve($this->sequenceId);
            } elseif ($frame instanceof ErrPacket) {
                $this->promise->reject($this->createAuthException($frame->errorCode, $frame->sqlState, $frame->errorMessage));
            } elseif ($frame instanceof AuthSwitchRequest) {
                $this->handleAuthPluginSwitch($frame);
            } elseif ($frame instanceof AuthMoreData) {
                $this->handleAuthMoreData($frame);
            }
        } catch (\Throwable $e) {
            $this->promise->reject(new AuthenticationException('Failed to process authentication packet: ' . $e->getMessage(), (int)$e->getCode(), $e));
        }
    }

    private function handleAuthPluginSwitch(AuthSwitchRequest $frame): void
    {
        try {
            $this->authPlugin = $frame->pluginName;
            $this->scramble = $frame->authData;
            $response = $this->generateAuthResponse($this->authPlugin, $this->scramble);
            $this->writePacket($response);
        } catch (\Throwable $e) {
            $this->promise->reject(new AuthenticationException('Failed to handle auth plugin switch: ' . $e->getMessage(), (int)$e->getCode(), $e));
        }
    }

    private function handleAuthMoreData(AuthMoreData $frame): void
    {
        try {
            if ($frame->isFullAuthRequired()) {
                $this->sendFullAuthCredentials();
            } elseif ($frame->isFastAuthSuccess()) {
                // Do nothing. Wait for the upcoming OK packet from the server.
                return;
            } else {
                $this->handleRsaPublicKey($frame->data);
            }
        } catch (\Throwable $e) {
            $this->promise->reject(new AuthenticationException('Failed to handle authentication continuation: ' . $e->getMessage(), (int)$e->getCode(), $e));
        }
    }

    private function sendFullAuthCredentials(): void
    {
        if ($this->isSslEnabled) {
            $this->writePacket($this->params->password . "\0");
        } else {
            $this->writePacket(\chr(0x02));
        }
    }

    private function handleRsaPublicKey(string $publicKey): void
    {
        try {
            $encrypted = AuthScrambler::scrambleSha256Rsa($this->params->password, $this->scramble, $publicKey);
            $this->writePacket($encrypted);
        } catch (\Throwable $e) {
            $this->promise->reject(new AuthenticationException('Failed to encrypt password with RSA: ' . $e->getMessage(), (int)$e->getCode(), $e));
        }
    }

    private function writePacket(string $payload): void
    {
        $packet = $this->packetWriter->write($payload, $this->sequenceId);
        $this->socket->write($packet);
        $this->sequenceId++;
    }

    private function generateAuthResponse(string $plugin, string $scramble): string
    {
        try {
            return match ($plugin) {
                'mysql_native_password' => AuthScrambler::scrambleNativePassword($this->params->password, $scramble),
                'caching_sha2_password' => AuthScrambler::scrambleCachingSha2Password($this->params->password, $scramble),
                default => '',
            };
        } catch (\Throwable $e) {
            throw new AuthenticationException("Failed to generate authentication response for plugin '{$plugin}': " . $e->getMessage(), (int)$e->getCode(), $e);
        }
    }

    private function createAuthException(int $errorCode, string $sqlState, string $message): AuthenticationException
    {
        $authErrorCodes = [
            1045 => 'Access denied - Invalid username or password',
            1040 => 'Too many connections',
            1129 => 'Host is blocked due to many connection errors',
            1130 => 'Host is not allowed to connect',
            1131 => 'Access denied - No permission to connect',
            1132 => 'Password change required',
            1133 => 'Password has expired',
            1227 => 'Access denied - Insufficient privileges',
            1251 => 'Client does not support authentication protocol',
            2049 => 'Connection using old authentication protocol refused',
        ];
        $errorDescription = $authErrorCodes[$errorCode] ?? 'Authentication failed';

        return new AuthenticationException("MySQL Authentication Error [{$errorCode}] [{$sqlState}]: {$message} - {$errorDescription}", $errorCode);
    }

    private function calculateCapabilities(): int
    {
        $flags = CapabilityFlags::CLIENT_PROTOCOL_41 |
            CapabilityFlags::CLIENT_SECURE_CONNECTION |
            CapabilityFlags::CLIENT_LONG_PASSWORD |
            CapabilityFlags::CLIENT_TRANSACTIONS |
            CapabilityFlags::CLIENT_PLUGIN_AUTH |
            CapabilityFlags::CLIENT_MULTI_RESULTS |
            CapabilityFlags::CLIENT_PS_MULTI_RESULTS |
            CapabilityFlags::CLIENT_CONNECT_WITH_DB |
            CapabilityFlags::CLIENT_PLUGIN_AUTH_LENENC_CLIENT_DATA;

        if ($this->params->useSsl()) {
            $flags |= CapabilityFlags::CLIENT_SSL;
        }

        // Enable Compression if requested and server supports it
        if ($this->params->compress && ($this->serverCapabilities & CapabilityFlags::CLIENT_COMPRESS) !== 0) {
            $flags |= CapabilityFlags::CLIENT_COMPRESS;
        }

        // Security Feature: Enable Multi-Statements (Stacked Queries) ONLY if explicitly requested
        if ($this->params->multiStatements) {
            $flags |= CapabilityFlags::CLIENT_MULTI_STATEMENTS;
        }

        return $flags;
    }
}
