#!/usr/bin/env sh
set -eu

ROOT_DIR=$(CDPATH= cd -- "$(dirname -- "$0")/../.." && pwd)
PHP_BIN=${PHP_BIN:-php}

cd "$ROOT_DIR"

failed=0
count=0

find . -path './.git' -prune -o -name '*.php' -print | while IFS= read -r file; do
  count=$((count + 1))
  if ! output=$("$PHP_BIN" -l "$file" 2>&1); then
    printf '%s\n' "$output"
    failed=1
  else
    case "$output" in
      *Deprecated*|*Warning*|*Fatal*)
        printf '%s\n' "$output"
        ;;
    esac
  fi
  printf '%s\n' "$count" > /tmp/epay_php_lint_count.$$
  printf '%s\n' "$failed" > /tmp/epay_php_lint_failed.$$
done

count=$(cat /tmp/epay_php_lint_count.$$ 2>/dev/null || printf '0')
failed=$(cat /tmp/epay_php_lint_failed.$$ 2>/dev/null || printf '0')
rm -f /tmp/epay_php_lint_count.$$ /tmp/epay_php_lint_failed.$$

printf 'Checked %s PHP files with %s\n' "$count" "$PHP_BIN"

if [ "$failed" -ne 0 ]; then
  exit 1
fi

