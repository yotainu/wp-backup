#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "$0")/.."
PHP_VERSION="${PHP_VERSION:-7.4}"
IMAGE="wppb-test:${PHP_VERSION}"
if [ ! -d vendor ]; then
  docker run --rm -v "$PWD":/app -w /app composer:2 install --no-interaction
fi
if ! docker image inspect "$IMAGE" >/dev/null 2>&1; then
  docker build --build-arg PHP_VERSION="$PHP_VERSION" -t "$IMAGE" -f Dockerfile.test .
fi
docker run --rm -v "$PWD":/app -w /app "$IMAGE" vendor/bin/phpunit "$@"
