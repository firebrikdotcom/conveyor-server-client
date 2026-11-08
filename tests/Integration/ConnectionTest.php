<?php

namespace Kanata\ConveyorServerClient\Tests\Integration;

use Kanata\ConveyorServerClient\Client;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Client::class)]
final class ConnectionTest extends TestCase
{
    public function testRealWebSocketHandshakeAndBroadcastEnvelope(): void
    {
        $listener = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
        self::assertIsResource($listener);
        $port = (int) substr(strrchr(stream_socket_get_name($listener, false), ':'), 1);
        $pid = pcntl_fork();
        self::assertNotSame(-1, $pid);
        if ($pid === 0) {
            $connection = stream_socket_accept($listener, 5);
            if (!$connection) { exit(1); }
            stream_set_timeout($connection, 8);
            $headers = '';
            while (!str_contains($headers, "\r\n\r\n")) {
                $chunk = fread($connection, 1024);
                if ($chunk === false || $chunk === '') { exit(2); }
                $headers .= $chunk;
            }
            preg_match('/Sec-WebSocket-Key: (.+)\r\n/i', $headers, $matches);
            $accept = base64_encode(sha1(trim($matches[1]).'258EAFA5-E914-47DA-95CA-C5AB0DC85B11', true));
            fwrite($connection, "HTTP/1.1 101 Switching Protocols\r\nUpgrade: websocket\r\nConnection: Upgrade\r\nSec-WebSocket-Accept: $accept\r\n\r\n");
            $read = static function (int $length) use ($connection): string {
                $data = '';
                while (strlen($data) < $length) {
                    $chunk = fread($connection, $length - strlen($data));
                    if ($chunk === '' || $chunk === false) { exit(3); }
                    $data .= $chunk;
                }
                return $data;
            };
            $frame = $read(2);
            $length = ord($frame[1]) & 127;
            $mask = $read(4);
            $payload = $read($length);
            for ($i = 0; $i < $length; $i++) { $payload[$i] = $payload[$i] ^ $mask[$i % 4]; }
            if (json_decode($payload, true) !== ['action' => 'broadcast-action', 'data' => 'hello']) { exit(4); }
            // Wait for the actual heartbeat while the client blocks in receive().
            $ping = $read(2);
            if ((ord($ping[0]) & 15) !== 9 || (ord($ping[1]) & 127) !== 0) { exit(5); }
            $read(4); // Mask key, empty ping payload.
            fwrite($connection, "\x81\x02OK");
            usleep(100000);
            fclose($connection);
            fclose($listener);
            exit(0);
        }
        fclose($listener);
        $received = [];
        try {
            $client = new Client([
                'port' => $port, 'timeout' => 8,
                'onReadyCallback' => static fn (Client $client) => $client->send('hello'),
                'onMessageCallback' => static function (Client $client, string $message) use (&$received) { $received[] = $message; $client->close(); },
            ]);
            $client->connect();
            self::assertSame(['OK'], $received);
            self::assertNull($client->getPingTimer());
        } finally {
            pcntl_waitpid($pid, $status);
        }
        self::assertTrue(pcntl_wifexited($status));
        self::assertSame(0, pcntl_wexitstatus($status));
    }
}
