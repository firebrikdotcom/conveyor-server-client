<?php

namespace Kanata\ConveyorServerClient\Tests\Unit;

use Kanata\ConveyorServerClient\Client;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use WebSocket\Client as WsClient;
use WebSocket\ConnectionException;
use WebSocket\TimeoutException;

class TestClient extends Client
{
    public WsClient $transport;
    public int $attempts = 0;
    public int $failures = 0;
    public array $created = [];

    protected function createClient(string $uri, array $options): WsClient
    {
        $this->attempts++;
        $this->created = [$uri, $options];
        if ($this->attempts <= $this->failures) {
            throw new ConnectionException('offline');
        }
        return $this->transport;
    }

    public function receiveMessages(): void { $this->handleClientConnection(); }
    public function disconnect(): void { $this->handleDisconnection(); }
}

#[CoversClass(Client::class)]
final class ClientTest extends TestCase
{
    public function testAssociatesUserJoinsChannelAndDeliversEvenFalseyMessages(): void
    {
        $sent = [];
        $received = [];
        $ready = false;
        $ws = $this->createMock(WsClient::class);
        $ws->expects(self::exactly(4))->method('send')->willReturnCallback(function ($message) use (&$sent) { $sent[] = json_decode($message, true); });
        $ws->method('receive')->willReturn('0', '', 'hello', null);
        $client = new TestClient([
            'protocol' => 'wss', 'uri' => 'example.org', 'port' => 443, 'query' => '?token=x',
            'channel' => 'team', 'userId' => 7, 'timeout' => 3,
            'onReadyCallback' => function (Client $client) use (&$ready) { $ready = true; $client->send('broadcast'); $client->sendRaw('{"raw":true}'); },
            'onMessageCallback' => function (Client $client, string $message) use (&$received) { $received[] = $message; },
        ]);
        $client->transport = $ws;
        $client->receiveMessages();
        self::assertTrue($ready);
        self::assertSame(['0', '', 'hello'], $received);
        self::assertSame([
            ['action' => 'assoc-user-to-fd-action', 'userId' => 7],
            ['action' => 'channel-connect', 'channel' => 'team'],
            ['action' => 'broadcast-action', 'data' => 'broadcast'], ['raw' => true],
        ], $sent);
        self::assertSame(['wss://example.org:443/?token=x', ['timeout' => 3]], $client->created);
        self::assertSame($ws, $client->getClient());
        $client->close();
        $client->close();
        self::assertNull($client->getClient());
        self::assertNull($client->getPingTimer());
    }

    public function testNoCallbacksAndNoAssociationAreOptional(): void
    {
        $ws = $this->createMock(WsClient::class);
        $ws->expects(self::never())->method('send');
        $ws->method('receive')->willReturn('ignored', null);
        $client = new TestClient([]);
        $client->transport = $ws;
        $client->receiveMessages();
        $client->disconnect();
        self::assertNull($client->getClient());
    }

    public function testFiniteTimeoutClosesConnection(): void
    {
        $ws = $this->createMock(WsClient::class);
        $ws->method('receive')->willThrowException(new TimeoutException('timeout'));
        $ws->expects(self::once())->method('close');
        $client = new TestClient(['timeout' => 1]);
        $client->transport = $ws;
        $client->receiveMessages();
        self::assertNull($client->getClient());
    }

    public function testInfiniteTimeoutPropagates(): void
    {
        $ws = $this->createStub(WsClient::class);
        $ws->method('receive')->willThrowException(new TimeoutException('timeout'));
        $client = new TestClient([]);
        $client->transport = $ws;
        $this->expectException(TimeoutException::class);
        $client->receiveMessages();
    }

    public function testConnectPropagatesUnlimitedTimeoutAndCleansUp(): void
    {
        $ws = $this->createMock(WsClient::class);
        $ws->method('receive')->willThrowException(new TimeoutException('timeout'));
        $ws->expects(self::once())->method('close');
        $client = new TestClient([]);
        $client->transport = $ws;
        try {
            $client->connect();
            self::fail('Expected timeout');
        } catch (TimeoutException $e) {
            self::assertSame('timeout', $e->getMessage());
        }
        self::assertNull($client->getPingTimer());
        self::assertNull($client->getClient());
    }

    public function testSendingBeforeConnectExplainsFailure(): void
    {
        $client = new Client([]);
        $this->expectException(\LogicException::class);
        $client->send('hello');
    }

    public function testAlreadyConnectedDoesNotStartAnotherScheduler(): void
    {
        $ws = $this->createMock(WsClient::class);
        $ws->method('receive')->willReturn(null);
        $ws->method('isConnected')->willReturn(true);
        $ws->expects(self::once())->method('close');
        $client = new TestClient(['logger' => new \Psr\Log\NullLogger()]);
        $client->transport = $ws;
        $client->receiveMessages();
        $client->connect();
        self::assertSame(1, $client->attempts);
        self::assertInstanceOf(\Psr\Log\NullLogger::class, $client->created[1]['logger']);
        $client->close();
    }

    public function testHeartbeatFailureUsesConnectionFailureCallbackWithoutFatalError(): void
    {
        $errors = [];
        $ws = $this->createMock(WsClient::class);
        $ws->method('isConnected')->willReturn(true);
        $ws->method('receive')->willReturnCallback(static function () {
            \OpenSwoole\Coroutine::sleep(6);
            return null;
        });
        $ws->expects(self::once())->method('ping')->willThrowException(new ConnectionException('ping failed'));
        $ws->expects(self::once())->method('disconnect');
        $ws->expects(self::once())->method('close');
        $client = new TestClient(['onReconnectionCallback' => static function (Client $client, \Throwable $error) use (&$errors): void { $errors[] = $error->getMessage(); }]);
        $client->transport = $ws;
        $client->connect();
        self::assertSame(['ping failed'], $errors);
        self::assertNull($client->getClient());
        self::assertNull($client->getPingTimer());
    }

    public function testCleanupFailureReachesCallerAndCloseRemainsIdempotent(): void
    {
        $ws = $this->createMock(WsClient::class);
        $ws->method('receive')->willReturn(null);
        $ws->expects(self::once())->method('close')->willThrowException(new ConnectionException('close failed'));
        $client = new TestClient([]);
        $client->transport = $ws;
        try {
            $client->connect();
            self::fail('Expected close failure');
        } catch (ConnectionException $e) {
            self::assertSame('close failed', $e->getMessage());
        }
        $client->close();
        self::assertNull($client->getClient());
        self::assertNull($client->getPingTimer());
    }

    public function testInvalidUtf8DoesNotSendAnEmptyPayload(): void
    {
        $ws = $this->createMock(WsClient::class);
        $ws->method('receive')->willReturn(null);
        $ws->expects(self::never())->method('send');
        $client = new TestClient([]);
        $client->transport = $ws;
        $client->receiveMessages();
        $this->expectException(\JsonException::class);
        $client->send("\xFF");
    }

    public function testReconnectIsBoundedAndCleansTimer(): void
    {
        $disconnected = [];
        $reconnecting = 0;
        $client = new TestClient([
            'reconnect' => true, 'reconnectionAttempts' => 2, 'reconnectionInterval' => 0,
            'onDisconnectCallback' => function (Client $client, int $attempts) use (&$disconnected) { $disconnected[] = $attempts; },
            'onReconnectionCallback' => function (Client $client, \Throwable $e) use (&$reconnecting) { $reconnecting++; self::assertInstanceOf(ConnectionException::class, $e); },
        ]);
        $client->failures = 10;
        $client->connect();
        self::assertSame(3, $client->attempts);
        self::assertSame([0, 1, 2], $disconnected);
        self::assertSame(3, $reconnecting);
        self::assertNull($client->getPingTimer());
    }

    public function testInfiniteRetriesStopOnSuccessfulConnection(): void
    {
        $ws = $this->createStub(WsClient::class);
        $ws->method('receive')->willReturn(null);
        $client = new TestClient(['reconnect' => true, 'reconnectionAttempts' => -1, 'reconnectionInterval' => 0]);
        $client->transport = $ws;
        $client->failures = 2;
        $client->connect();
        self::assertSame(3, $client->attempts);
        self::assertNull($client->getClient());
    }

    public function testReconnectDisabledDoesNotRetry(): void
    {
        $client = new TestClient([]);
        $client->failures = 2;
        $client->connect();
        self::assertSame(1, $client->attempts);
        self::assertNull($client->getPingTimer());
    }
}
