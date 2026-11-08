<?php

namespace Kanata\ConveyorServerClient;

use WebSocket\Client as WsClient;

interface ClientInterface
{
    public function connect(): void;
    public function getClient(): ?WsClient;
    public function close(): void;
    public function send(string $message): void;
    public function sendRaw(string $message): void;
}
