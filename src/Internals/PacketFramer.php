<?php

declare(strict_types=1);

namespace Hibla\Mysql\Internals;

use Rcalicdan\MySQLBinaryProtocol\Packet\PacketWriter;

/**
 * Handles splitting large payloads into MySQL's 16MB packet chunks.
 *
 * @internal
 */
final class PacketFramer
{
    /**
     * The maximum size of a single MySQL packet payload (16MB - 1 byte).
     */
    public const int MAX_PACKET_SIZE = 16777215; // 0xFFFFFF

    /**
     * Lazily yields formatted packet chunks for writing to the socket.
     * 
     * Using a Generator prevents memory doubling on very large payloads 
     * by only framing the current chunk in memory.
     *
     * @param string $payload The raw command payload.
     * @param PacketWriter $writer The protocol packet writer.
     * @param int $sequenceId The current sequence ID, automatically incremented.
     * @return \Generator<int, string>
     */
    public function frame(string $payload, PacketWriter $writer, int &$sequenceId): \Generator
    {
        $length = \strlen($payload);
        $offset = 0;

        while ($length >= self::MAX_PACKET_SIZE) {

            $chunk = substr($payload, $offset, self::MAX_PACKET_SIZE);
            yield $writer->write($chunk, $sequenceId);

            $sequenceId++;
            $length -= self::MAX_PACKET_SIZE;
            $offset += self::MAX_PACKET_SIZE;
        }

        $chunk = substr($payload, $offset);
        yield $writer->write($chunk, $sequenceId);
        
        $sequenceId++;
    }
}