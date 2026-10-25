#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "$0")/.."
PHP_IMAGE="${PHP_IMAGE:-php:7.4-cli}"
if [ ! -d vendor ]; then
  docker run --rm -v "$PWD":/app -w /app composer:2 install --no-interaction --ignore-platform-reqs
fi
docker run --rm -v "$PWD":/app -w /app "$PHP_IMAGE" vendor/bin/phpunit "$@"
