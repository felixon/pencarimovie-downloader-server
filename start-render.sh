#!/usr/bin/env bash
set -e

ROOT_DIR="/app"
FRANKENPHP_BIN="$ROOT_DIR/bin/frankenphp"
HOST="0.0.0.0"
PORT="${PORT:-10000}"

echo "======================================"
echo "Starting PencariMovie Downloader"
echo "Host: $HOST"
echo "Port: $PORT"
echo "======================================"

if [ ! -x "$FRANKENPHP_BIN" ]; then
    echo "ERROR: FrankenPHP binary not found at $FRANKENPHP_BIN"
    exit 1
fi

export PATH="$ROOT_DIR/bin:$PATH"
export PHP_BINDIR="$ROOT_DIR/bin"
export PHPRC="$ROOT_DIR/bin"

exec "$FRANKENPHP_BIN" php-server \
    --listen "$HOST:$PORT" \
    --root "$ROOT_DIR"
