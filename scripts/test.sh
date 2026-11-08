#!/bin/sh
set -eu
cd "$(dirname "$0")/.."
if php -r 'exit(extension_loaded("openswoole") ? 0 : 1);'; then
    exec php vendor/bin/phpunit "$@"
fi
exec docker run --rm --user "$(id -u):$(id -g)" \
    -v "$PWD:/app" -w /app --entrypoint php \
    "${CONVEYOR_TEST_IMAGE:-lotharthesavior/socket-conveyor:2.6.1}" \
    vendor/bin/phpunit "$@"
