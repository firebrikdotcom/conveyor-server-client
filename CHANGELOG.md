# Change Log
All notable changes to this project will be documented in this file.
Updates should follow the [Keep a CHANGELOG](https://keepachangelog.com/) principles.

## [0.2.0] - Unreleased

### Changed

- Require PHP 8.3+ and OpenSwoole 26.2.x explicitly; development uses PHPUnit 13.4.6 on PHP 8.4.1+.
- Update Textalk WebSocket to 1.6.3 and declare PSR logging 3.0.2 directly.
- Remove the obsolete result printer and development server/core dependencies; use a standalone protocol fixture and extension scheduler.
- Replace skipped server tests with deterministic lifecycle tests and real WebSocket integration coverage.
- Modernize CI with pinned actions, PHP 8.3 consumer checks, PHP 8.4/8.5 tests, coverage and Composer audit.
- Use canonical Conveyor branding and correct installation/options documentation.

### Fixed

- Deliver empty and `"0"` messages, report sends before connection clearly, and reject invalid JSON encoding.
- Bound reconnect attempts without recursively nesting coroutine schedulers; clear timers and connections after completion.
- Avoid clearing a null timer and make repeated close calls safe.

### Migration

This pre-1.0 breaking release increments the minor version from 0.1.6 to 0.2.0.
Install OpenSwoole 26.2.x and upgrade PHP from 8.2 to 8.3 or newer. Upgrade development PHP to 8.4.1+.
Runtime server packages are no longer installed as test dependencies. `send()` can now throw `JsonException` for malformed UTF-8 and `LogicException` before connecting.

## [0.1.0] through [0.1.6]

These existing Git/Packagist tags predate this refresh. Their changes were not recorded in the previous changelog; consult the corresponding Git history.

## [0.0.4] - 2023-01-14

### Added

- Added infinite reconnection attempts option.

## [0.0.3] - 2022-10-16

### Added

- Added disconnection callback.

## [0.0.2] - 2022-10-16

### Added

- Added reconnection feature. Now, if it fails to connect or disconnects after connected, it will try to reconnect according to the settings.

## [0.0.1] - 2022-09-25

### Initial version

- Fully functional server-side Websocket Client ready for [Socket Conveyor](https://socketconveyor.com).
