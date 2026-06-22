#!/usr/bin/env sh
set -eu

ROOT_DIR=$(CDPATH= cd -- "$(dirname -- "$0")/../.." && pwd)
cd "$ROOT_DIR"

pattern='(^|[^[:alnum:]_$.])each[[:space:]]*\(|create_function[[:space:]]*\(|__autoload[[:space:]]*\(|(^|[^[:alnum:]_])mysql_[[:alnum:]_]*[[:space:]]*\(|(^|[^[:alnum:]_])mcrypt_[[:alnum:]_]*[[:space:]]*\(|(^|[^[:alnum:]_])ereg(i)?[[:space:]]*\(|set_magic_quotes_runtime[[:space:]]*\(|get_magic_quotes_gpc[[:space:]]*\(|strftime[[:space:]]*\('

matches=$(find . \
  -path './.git' -prune -o \
  -path './includes/vendor' -prune -o \
  -path './tools' -prune -o \
  -name '*.php' -print | xargs grep -nE "$pattern" 2>/dev/null || true)

if [ -n "$matches" ]; then
  printf '%s\n' "$matches"
  exit 1
fi

printf 'No blocked deprecated PHP patterns found in non-vendor PHP files.\n'
