<p align="center">
  <picture>
  <source media="(prefers-color-scheme: dark)" srcset="imgs/conveyor-logo-dark.svg">
  <img width="96" height="96" style="max-height: 96px; max-width: 96px;" src="imgs/conveyor-logo-light.svg" alt="Socket Conveyor — conveyor-server-client PHP package">
  </picture>
</p>

# Conveyor Server Client

A PHP WebSocket client for [Socket Conveyor](https://socketconveyor.com).
Composer package: [`firebrikdotcom/conveyor-server-client`](https://packagist.org/packages/firebrikdotcom/conveyor-server-client).

Version **0.2.0** is published as `firebrikdotcom/conveyor-server-client` on Packagist. Existing projects using `kanata-php/conveyor-server-client` can migrate by updating their Composer requirement; the previous package remains available.

## Requirements and install

Version **0.2.0** requires PHP **8.3+**, Composer 2, and **OpenSwoole 26.2 or newer (below 27)**.
Development requires PHP **8.4.1+** for PHPUnit 13; PHP 8.3 remains supported for consumers.
TLS connections (`wss`) require OpenSSL. The server is installed separately; it is not a client dependency.

```sh
pecl install openswoole-26.2.0
# Enable extension=openswoole.so in your PHP configuration.
composer require firebrikdotcom/conveyor-server-client:^0.2
```

The published 0.2.0 release uses the new `firebrikdotcom` Composer name.

## Usage

```php
<?php
require __DIR__ . '/vendor/autoload.php';

use Kanata\ConveyorServerClient\Client;

$client = new Client([
    'uri' => '127.0.0.1',
    'port' => 8000,
    'channel' => 'sample-channel',
    'timeout' => 30,
    'onReadyCallback' => function (Client $client): void {
        $client->send('Hello from PHP');
    },
    'onMessageCallback' => function (Client $client, string $message): void {
        echo $message, PHP_EOL;
        $client->close();
    },
]);
$client->connect();
```

`connect()` runs the receive loop until closed, timed out, or disconnected.
`send()` encodes `{"action":"broadcast-action","data":"..."}`; `sendRaw()` sends an exact string.
Call either after connecting (for example in `onReadyCallback`). Invalid UTF-8 in JSON payloads throws `JsonException`.
`close()` is safe to call repeatedly and clears the heartbeat timer.

Supported options and defaults:

| Option | Default | Meaning |
| --- | --- | --- |
| `protocol` | `ws` | `ws` or `wss` |
| `uri` | `127.0.0.1` | Host name |
| `port` | `8000` | Server port |
| `query` | `''` | URL suffix after `/`, e.g. `?token=...` |
| `channel` | `null` | Channel to join before ready callback |
| `userId` | `null` | Integer user ID to associate before channel join |
| `timeout` | `-1` | Socket read timeout in seconds; `-1` uses the transport's unlimited timeout |
| `reconnect` | `false` | Retry connection failures |
| `reconnectionAttempts` | `0` | Additional attempts; `-1` retries indefinitely |
| `reconnectionInterval` | `2` | Delay in whole seconds |
| `logger` | `null` | PSR-3 `LoggerInterface` |
| `onReadyCallback` | `null` | `function (Client $client): void` |
| `onMessageCallback` | `null` | `function (Client $client, string $message): void` |
| `onDisconnectCallback` | `null` | `function (Client $client, int $attempts): void` |
| `onReconnectionCallback` | `null` | `function (Client $client, Throwable $error): void` called on connection failure, even when retries are disabled |

A finite read timeout closes the connection without retrying. An unlimited-mode transport timeout propagates.
`getClient(): ?WebSocket\Client` exposes the Textalk transport; `getPingTimer(): ?int` exposes the active timer ID.
The former README's `listen` and `onOpenCallback` options were never implemented and are no longer advertised.

## Development

```sh
composer install
composer lint
composer test
composer coverage  # requires PCOV or Xdebug; writes coverage.xml
composer audit
```

Tests cover protocol envelopes, association/channel order, callbacks, falsey messages, retries, timeout handling, cleanup, and a real local WebSocket handshake. They do not require a running Conveyor server. Unix integration tests require `pcntl`.

When host PHP lacks OpenSwoole, `composer test` uses an isolated `docker run --rm` with the existing `lotharthesavior/socket-conveyor:2.6.1` image (PHP 8.5.12/OpenSwoole 26.2.0). Install dependencies in an environment with the required extensions; a host-only dependency preparation may use `composer install --ignore-platform-req=ext-openswoole`, followed by tests inside Docker. Coverage needs a Docker image with PCOV/Xdebug; select it with `CONVEYOR_TEST_IMAGE=your-image composer coverage`.

The latest Textalk release is 1.6.3. It emits an upstream nullable-parameter deprecation on PHP 8.4+; PHPUnit excludes vendor deprecations while continuing to fail on project deprecations. Its transport API is retained for compatibility.

## Release preparation

See [CHANGELOG.md](CHANGELOG.md). Validate with `composer validate --strict`, `composer audit`, tests and coverage, then `composer archive --format=zip --dir=/tmp/conveyor-client-release` to inspect the package. Composer derives its version from Git tags; do not add a hardcoded `version` field.

Packagist registers `firebrikdotcom/conveyor-server-client` from [this repository](https://github.com/firebrikdotcom/conveyor-server-client), with **0.2.0** published under the new name. For future releases, verify maintainer and webhook access, publish a new version tag, and confirm Packagist indexes that exact commit. The previous `kanata-php/conveyor-server-client` package remains available for existing installations. There is no automated publication workflow.

## License

MIT. Copyright © 2022 Savio Resende. [Author](https://github.com/lotharthesavior).
