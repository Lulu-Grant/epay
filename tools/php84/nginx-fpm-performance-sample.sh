#!/usr/bin/env sh
set -u

ROOT_DIR=$(CDPATH= cd -- "$(dirname -- "$0")/../.." && pwd)
SMOKE_SCRIPT="$ROOT_DIR/tools/php84/nginx-fpm-smoke.sh"
PHP74_BIN=${PHP74_BIN:-/usr/bin/php7.4}
PHP74_FPM_BIN=${PHP74_FPM_BIN:-/usr/sbin/php-fpm7.4}
PHP84_BIN=${PHP84_BIN:-/usr/bin/php8.4}
PHP84_FPM_BIN=${PHP84_FPM_BIN:-/usr/sbin/php-fpm8.4}
NGINX_BIN=${NGINX_BIN:-nginx}
SAMPLE_COUNT=${PERF_SAMPLE_COUNT:-3}
REPEAT_COUNT=${EPAY_NGINX_PERF_REPEAT_COUNT:-2}
PORT_BASE=${EPAY_NGINX_PERF_PORT_BASE:-18500}
WORK_DIR=$(mktemp -d "${TMPDIR:-/tmp}/epay-nginx-perf-repeat.XXXXXX") || exit 1
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

case "$REPEAT_COUNT" in
  ''|*[!0-9]*)
    printf 'EPAY_NGINX_PERF_REPEAT_COUNT must be a positive integer, got: %s\n' "$REPEAT_COUNT" >&2
    exit 1
    ;;
esac

if [ "$SAMPLE_COUNT" -lt 2 ]; then
  printf 'PERF_SAMPLE_COUNT must be at least 2 for repeated sampling.\n' >&2
  exit 1
fi

check_bin() {
  check_label=$1
  check_bin_path=$2

  if ! command -v "$check_bin_path" >/dev/null 2>&1; then
    printf '[FAIL] %s_bin: not found: %s\n' "$check_label" "$check_bin_path"
    failures=$((failures + 1))
    return 1
  fi
  return 0
}

parse_perf_lines() {
  parse_label=$1
  parse_sample=$2
  output_file=$3

  awk -v label="$parse_label" -v sample="$parse_sample" '
    /^\[PERF\] / {
      metric = $2
      if (metric ~ /^stability_/ || metric ~ /^protected_/) {
        next
      }
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
  run_label=$1
  php_bin=$2
  fpm_bin=$3
  offset=$4

  check_bin "${run_label}_php" "$php_bin" || return
  check_bin "${run_label}_fpm" "$fpm_bin" || return
  check_bin nginx "$NGINX_BIN" || return

  printf '[INFO] %s_php=%s\n' "$run_label" "$("$php_bin" -r 'echo PHP_VERSION;')"
  i=1
  while [ "$i" -le "$SAMPLE_COUNT" ]; do
    http_port=$((PORT_BASE + offset + i))
    fpm_port=$((PORT_BASE + offset + 100 + i))
    output_file="$WORK_DIR/${run_label}_${i}.log"

    printf '[INFO] %s_sample_%s: http_port=%s fpm_port=%s\n' "$run_label" "$i" "$http_port" "$fpm_port"
    if PHP_BIN="$php_bin" PHP_FPM_BIN="$fpm_bin" NGINX_BIN="$NGINX_BIN" \
      EPAY_NGINX_SMOKE_PORT="$http_port" EPAY_FPM_SMOKE_PORT="$fpm_port" \
      EPAY_NGINX_REPEAT_COUNT="$REPEAT_COUNT" sh "$SMOKE_SCRIPT" >"$output_file" 2>&1; then
      parse_perf_lines "$run_label" "$i" "$output_file"
      printf '[OK] %s_sample_%s\n' "$run_label" "$i"
    else
      printf '[FAIL] %s_sample_%s: Nginx/PHP-FPM smoke failed\n' "$run_label" "$i"
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
  printf '\n[NGINX-PERF-SUMMARY] samples_per_runtime=%s repeat_per_sample=%s\n' "$SAMPLE_COUNT" "$REPEAT_COUNT"
  printf '[NGINX-PERF-SUMMARY] metric php74_median_ms php84_median_ms delta_percent sample_count\n'

  metrics=$(awk -F '\t' '{ print $3 }' "$PERF_TSV" | sort -u)
  for metric in $metrics; do
    php74_count=$(count_for php74 "$metric")
    php84_count=$(count_for php84 "$metric")

    if [ "$php74_count" -eq 0 ] || [ "$php84_count" -eq 0 ]; then
      printf '[FAIL] nginx_perf_summary_%s: missing samples php74=%s php84=%s\n' "$metric" "$php74_count" "$php84_count"
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

    printf '[NGINX-PERF-SUMMARY] %s %s %s %s%% %s/%s\n' "$metric" "$php74_median" "$php84_median" "$delta" "$php74_count" "$php84_count"
  done
}

: >"$PERF_TSV"

run_label_samples php74 "$PHP74_BIN" "$PHP74_FPM_BIN" 0
run_label_samples php84 "$PHP84_BIN" "$PHP84_FPM_BIN" 300

if [ ! -s "$PERF_TSV" ]; then
  printf '[FAIL] no Nginx/PHP-FPM performance samples collected\n'
  failures=$((failures + 1))
else
  print_summary
fi

if [ "$failures" -ne 0 ]; then
  printf '\nNginx/PHP-FPM performance repeated sampling failed with %s failure(s).\n' "$failures"
  exit 1
fi

printf '\nNginx/PHP-FPM performance repeated sampling completed.\n'
