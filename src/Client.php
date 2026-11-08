<?php

namespace Kanata\ConveyorServerClient;

use OpenSwoole\Coroutine;
use OpenSwoole\Coroutine\Scheduler;
use Exception;
use OpenSwoole\Timer;
use Psr\Log\LoggerInterface;
use Throwable;
use WebSocket\BadOpcodeException;
use WebSocket\Client as WsClient;
use WebSocket\ConnectionException;
use WebSocket\TimeoutException;

class Client implements ClientInterface
{
    protected ?WsClient $client = null;

    protected string $protocol = 'ws';
    protected string $uri = '127.0.0.1';
    protected int $port = 8000;
    protected string $query = '';
    protected ?string $channel = null;
    protected ?int $userId = null;

    /**
     * Callback for when the server is disconnecting.
     *
     * @var mixed|null
     */
    protected $onDisconnectCallback = null;

    /**
     * Callback for after the connection is established.
     *
     * @var ?callable
     */
    protected $onReadyCallback = null;

    /**
     * Message handler.
     *
     * @var ?callable
     */
    protected $onMessageCallback = null;

    /**
     * Callback for Reconnection moment.
     *
     * @var callable|null
     */
    protected $onReconnectionCallback = null;

    /**
     * Connection timeout.
     *
     * @var int
     */
    protected $timeout = -1;

    /**
     * Reconnect.
     *
     * @var bool
     */
    protected bool $reconnect = false;

    /**
     * Reconnection attempts to retry.
     *
     * @var int
     */
    protected int $reconnectionAttempts = 0;

    /**
     * Reconnection interval in seconds.
     *
     * @var int
     */
    protected int $reconnectionInterval = 2;

    /**
     * Reconnection attempts count for control.
     *
     * @var int
     */
    protected int $reconnectionAttemptsCount = 0;

    /**
     * @var ?int Timer id for ping.
     */
    protected ?int $pingTimer = null;

    protected ?LoggerInterface $logger = null;

    public function __construct(array $options)
    {
        foreach ($options as $key => $value) {
            if (property_exists($this, $key)) {
                $this->{$key} = $value;
            }
        }
    }

    public function getClient(): ?WsClient
    {
        return $this->client;
    }

    public function getPingTimer(): ?int
    {
        return $this->pingTimer;
    }

    /**
     * @throws TimeoutException|Exception
     */
    public function connect(): void
    {
        if ($this->getClient()?->isConnected()) {
            return;
        }

        if ($this->client !== null) {
            $this->close();
        }

        $scheduler = new Scheduler();
        $scheduler->set(['hook_flags' => \OpenSwoole\Runtime::HOOK_ALL]);
        $error = null;
        $scheduler->add(function () use (&$error) {
            try {
                $this->startConnection();
            } catch (Throwable $e) {
                $error = $e;
            } finally {
                try {
                    $this->close();
                } catch (Throwable $e) {
                    $error ??= $e;
                }
            }
        });
        $scheduler->start();
        if ($error !== null) {
            throw $error;
        }
    }

    private function startConnection(): void
    {
        $this->reconnectionAttemptsCount = 0;
        do {
            $heartbeatError = null;
            try {
                $this->pingTimer = Timer::tick(5000, function () use (&$heartbeatError) {
                    try {
                        if ($this->getClient()?->isConnected()) {
                            $this->getClient()->ping();
                        }
                    } catch (Throwable $e) {
                        $heartbeatError = $e;
                        // Abort the socket read; a graceful close can itself block.
                        try {
                            $this->getClient()?->disconnect();
                        } catch (Throwable) {
                            // Preserve the original heartbeat failure.
                        }
                    }
                });
                try {
                    $this->handleClientConnection();
                } catch (Throwable $e) {
                    throw $heartbeatError ?? $e;
                }
                if ($heartbeatError !== null) {
                    throw $heartbeatError;
                }
                return;
            } catch (TimeoutException $e) {
                throw $e;
            } catch (Exception $e) {
                $this->handleDisconnection();
                if (is_callable($this->onReconnectionCallback)) {
                    call_user_func($this->onReconnectionCallback, $this, $e);
                }
                if (!$this->reconnect || ($this->reconnectionAttempts !== -1
                    && $this->reconnectionAttemptsCount >= $this->reconnectionAttempts)) {
                    return;
                }
                if ($this->reconnectionInterval > 0) {
                    Coroutine::sleep($this->reconnectionInterval);
                }
                $this->reconnectionAttemptsCount++;
            }
        } while (true);
    }

    /**
     * @throws BadOpcodeException
     */
    public function send(string $message): void
    {
        $this->requireClient()->send(json_encode([
            'action' => 'broadcast-action',
            'data' => $message,
        ], JSON_THROW_ON_ERROR));
    }

    /**
     * @throws BadOpcodeException
     */
    public function sendRaw(string $message): void
    {
        $this->requireClient()->send($message);
    }

    protected function handleDisconnection(): void
    {
        $this->close();

        if (null === $this->onDisconnectCallback) {
            return;
        }

        call_user_func(
            $this->onDisconnectCallback,
            $this,
            $this->reconnectionAttemptsCount
        );
    }

    /**
     * @throws TimeoutException|Exception|ConnectionException
     */
    protected function handleClientConnection(): void
    {
        $this->client = $this->createClient(
            uri: "{$this->protocol}://{$this->uri}:{$this->port}/{$this->query}",
            options: array_merge([
                'timeout' => $this->timeout,
            ], $this->logger ? [
                'logger' => $this->logger,
            ] : []),
        );

        $this->handleUserAssociation();
        $this->handleChannelConnection();
        $this->connectionReady();

        try {
            while ($this->client && ($message = $this->client->receive()) !== null) {
                if (null === $this->onMessageCallback) {
                    continue;
                }
                call_user_func($this->onMessageCallback, $this, $message);
            }
        } catch (TimeoutException $e) {
            if ($this->timeout === -1) {
                throw $e;
            }
            $this->close();
        } catch (Exception $e) {
            throw $e;
        }
    }

    protected function handleUserAssociation(): void
    {
        if (null === $this->userId) {
            return;
        }

        $this->requireClient()->send(json_encode([
            'action' => 'assoc-user-to-fd-action',
            'userId' => $this->userId,
        ], JSON_THROW_ON_ERROR));
    }

    protected function handleChannelConnection(): void
    {
        if (null === $this->channel) {
            return;
        }

        $this->requireClient()->send(json_encode([
            'action' => 'channel-connect',
            'channel' => $this->channel,
        ], JSON_THROW_ON_ERROR));
    }

    protected function connectionReady(): void
    {
        if (null === $this->onReadyCallback) {
            return;
        }

        call_user_func($this->onReadyCallback, $this);
    }

    protected function createClient(string $uri, array $options): WsClient
    {
        return new WsClient($uri, $options);
    }

    private function requireClient(): WsClient
    {
        return $this->client ?? throw new \LogicException('Connect before sending a message.');
    }

    public function close(): void
    {
        if ($this->pingTimer !== null) {
            Timer::clear($this->pingTimer);
            $this->pingTimer = null;
        }
        $client = $this->client;
        $this->client = null;
        $client?->close();
    }
}
