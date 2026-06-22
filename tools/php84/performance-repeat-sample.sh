#!/usr/bin/env sh
set -u

ROOT_DIR=$(CDPATH= cd -- "$(dirname -- "$0")/../.." && pwd)
SMOKE_SCRIPT="$ROOT_DIR/tools/php84/installed-http-smoke.sh"
PHP74_BIN=${PHP74_BIN:-/opt/homebrew/opt/php@7.4/bin/php}
PHP84_BIN=${PHP84_BIN:-/opt/homebrew/opt/php@8.4/bin/php}
SAMPLE_COUNT=${PERF_SAMPLE_COUNT:-3}
PORT_BASE=${EPAY_PERF_PORT_BASE:-8240}
WORK_DIR=$(mktemp -d "${TMPDIR:-/tmp}/epay-perf-repeat.XXXXXX") || exit 1
PERF_TSV="$WORK_DIR/perf.tsv"
failures=0

cleanup() {
  rm -rf "$WORK_DIR"
}

trap cleanup EXIT INT TERM

case "$SAMPLE_COUNT" in
  ''|*[!0-9]*)
    printf 'PERF_SAMPLE_COUNT must be a positive integer, got: %s\n' "$SAMPLE_COUNT" >&2
    exit 1
    ;;
esac

if [ "$SAMPLE_COUNT" -lt 2 ]; then
  printf 'PERF_SAMPLE_COUNT must be at least 2 for repeated sampling.\n' >&2
  exit 1
fi

if [ ! -x "$SMOKE_SCRIPT" ]; then
  printf 'Smoke script is not executable: %s\n' "$SMOKE_SCRIPT" >&2
  exit 1
fi

check_php_bin() {
  label=$1
  php_bin=$2

  if ! command -v "$php_bin" >/dev/null 2>&1; then
    printf '[FAIL] %s_php_bin: not found: %s\n' "$label" "$php_bin"
    failures=$((failures + 1))
    return 1
  fi

  printf '[INFO] %s_php=%s\n' "$label" "$("$php_bin" -r 'echo PHP_VERSION;')"
  return 0
}

parse_perf_lines() {
  label=$1
  sample=$2
  output_file=$3

  awk -v label="$label" -v sample="$sample" '
    /^\[PERF\] / && $2 !~ /^runtime=/ {
      metric = $2
      ms = ""
      for (i = 3; i <= NF; i++) {
        if ($i ~ /^ms=/) {
          ms = $i
          sub(/^ms=/, "", ms)
        }
      }
      if (ms != "" && ms ~ /^[0-9]+([.][0-9]+)?$/) {
        printf "%s\t%s\t%s\t%s\n", label, sample, metric, ms
      }
    }
  ' "$output_file" >>"$PERF_TSV"
}

run_label_samples() {
  label=$1
  php_bin=$2
  offset=$3

  check_php_bin "$label" "$php_bin" || return

  i=1
  while [ "$i" -le "$SAMPLE_COUNT" ]; do
    port=$((PORT_BASE + offset + i))
    output_file="$WORK_DIR/${label}_${i}.log"

    printf '[INFO] %s_sample_%s: port=%s\n' "$label" "$i" "$port"
    if PHP_BIN="$php_bin" EPAY_SMOKE_PORT="$port" sh "$SMOKE_SCRIPT" >"$output_file" 2>&1; then
      parse_perf_lines "$label" "$i" "$output_file"
      printf '[OK] %s_sample_%s\n' "$label" "$i"
    else
      printf '[FAIL] %s_sample_%s: installed smoke failed\n' "$label" "$i"
      tail -n 80 "$output_file"
      failures=$((failures + 1))
    fi

    i=$((i + 1))
  done
}

median_for() {
  label=$1
  metric=$2

  awk -F '\t' -v label="$label" -v metric="$metric" '
    $1 == label && $3 == metric { print $4 }
  ' "$PERF_TSV" | sort -n | awk '
    { values[++count] = $1 }
    END {
      if (count == 0) {
        exit 1
      }
      if (count % 2 == 1) {
        printf "%.2f", values[(count + 1) / 2]
      } else {
        printf "%.2f", (values[count / 2] + values[count / 2 + 1]) / 2
      }
    }
  '
}

count_for() {
  label=$1
  metric=$2

  awk -F '\t' -v label="$label" -v metric="$metric" '
    $1 == label && $3 == metric { count++ }
    END { print count + 0 }
  ' "$PERF_TSV"
}

print_summary() {
  printf '\n[PERF-SUMMARY] samples_per_runtime=%s\n' "$SAMPLE_COUNT"
  printf '[PERF-SUMMARY] metric php74_median_ms php84_median_ms delta_percent sample_count\n'

  metrics=$(awk -F '\t' '{ print $3 }' "$PERF_TSV" | sort -u)
  for metric in $metrics; do
    php74_count=$(count_for php74 "$metric")
    php84_count=$(count_for php84 "$metric")

    if [ "$php74_count" -eq 0 ] || [ "$php84_count" -eq 0 ]; then
      printf '[FAIL] perf_summary_%s: missing samples php74=%s php84=%s\n' "$metric" "$php74_count" "$php84_count"
      failures=$((failures + 1))
      continue
    fi

    php74_median=$(median_for php74 "$metric") || php74_median=""
    php84_median=$(median_for php84 "$metric") || php84_median=""
    delta=$(awk -v old="$php74_median" -v new="$php84_median" 'BEGIN {
      if (old == 0) {
        print "n/a"
      } else {
        printf "%+.1f", ((new - old) / old) * 100
      }
    }')

    printf '[PERF-SUMMARY] %s %s %s %s%% %s/%s\n' "$metric" "$php74_median" "$php84_median" "$delta" "$php74_count" "$php84_count"
  done
}

: >"$PERF_TSV"

run_label_samples php74 "$PHP74_BIN" 0
run_label_samples php84 "$PHP84_BIN" 100

if [ ! -s "$PERF_TSV" ]; then
  printf '[FAIL] no performance samples collected\n'
  failures=$((failures + 1))
else
  print_summary
fi

if [ "$failures" -ne 0 ]; then
  printf '\nPerformance repeated sampling failed with %s failure(s).\n' "$failures"
  exit 1
fi

printf '\nPerformance repeated sampling completed.\n'
