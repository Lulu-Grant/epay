#!/usr/bin/env sh
set -eu

ROOT_DIR=$(CDPATH= cd -- "$(dirname -- "$0")/../.." && pwd)
COMPOSER_BIN=${COMPOSER_BIN:-composer}
PHP_BIN=${PHP_BIN:-php}

if [ "$PHP_BIN" != "php" ]; then
  PHP_DIR=$(dirname -- "$PHP_BIN")
  PATH="$PHP_DIR:$PATH"
  export PATH
fi

cd "$ROOT_DIR/includes"

if ! command -v "$COMPOSER_BIN" >/dev/null 2>&1; then
  printf 'Composer not found: %s\n' "$COMPOSER_BIN" >&2
  exit 1
fi

"$COMPOSER_BIN" validate --strict

if [ -f composer.lock ]; then
  "$COMPOSER_BIN" install --no-dev --no-interaction --prefer-dist
  "$COMPOSER_BIN" check-platform-reqs
else
  printf 'composer.lock is missing; skipping install until dependency locking is completed.\n'
  exit 2
fi
