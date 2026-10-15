#!/usr/bin/env bash
# The suite as a GATE against a known-red baseline (TEST-LIVE-ROWS, decided 2026-10-15).
#
# The local database is a production copy, so a known set of tests fails on production's rows
# rather than on the code. Until the frozen test database is built (deferred, costed on the
# backlog), this script runs the full suite and compares the FAILING SET, by test name, against
# tests/baseline/suite-baseline.txt, and prints three lines:
#   1. failures that ARE in the baseline            — known, environmental
#   2. failures that are NOT in the baseline         — THE GATE TRIPS (exit 1)
#   3. baseline entries that now PASS                — the baseline is stale; shrink it (exit 2)
# It exits 0 only when both the second and the third line are empty.
#
# What it does NOT catch: a real regression that lands INSIDE one of the baselined tests — that
# test is already red, so it cannot turn red. The size of that blind spot is printed with the
# result (baselined tests / tests in the suite). Keep the baseline small; every entry is a test
# that is not guarding anything.
#
# Usage, from core/:
#   bash tests/baseline/suite-baseline.sh            # runs php artisan test, then compares
#   bash tests/baseline/suite-baseline.sh --log F    # compares an existing plain-text Pest log F
set -u
cd "$(dirname "$0")/../.." || exit 3
BASELINE="tests/baseline/suite-baseline.txt"
LOG=""
if [ "${1:-}" = "--log" ]; then LOG="${2:-}"; [ -f "$LOG" ] || { echo "no such log: $LOG" >&2; exit 3; }; fi
if [ -z "$LOG" ]; then
  LOG="$(mktemp -t suite-baseline-XXXXXX).log"
  start=$(date +%s)
  php artisan test 2>&1 | sed -E 's/\x1b\[[0-9;]*m//g' > "$LOG"
  echo "suite: $(grep -E '^\s*Tests:' "$LOG" | tail -1 | sed -E 's/^\s*//') — $(( $(date +%s) - start )) s — log $LOG"
else
  echo "suite (from log): $(grep -E '^\s*Tests:' "$LOG" | tail -1 | sed -E 's/^\s*//')"
fi
grep -qE '^\s*Tests:' "$LOG" || { echo "the log holds no Pest summary — not a full run" >&2; exit 3; }

# The failing set, by "Class > name", exactly as Pest prints the FAILED lines (the name may be
# truncated by Pest with an ellipsis; the baseline stores the same truncated form).
failed=$(grep -E '^\s*FAILED\s+Tests\\' "$LOG" | sed -E 's/^\s*FAILED\s+//; s/\s+$//' | sort -u)
baseline=$(grep -vE '^\s*(#|$)' "$BASELINE" | sed -E 's/\s*\|.*$//; s/\s+$//' | sort -u)

known=$(comm -12 <(printf '%s\n' "$failed") <(printf '%s\n' "$baseline"))
new=$(comm -23 <(printf '%s\n' "$failed") <(printf '%s\n' "$baseline"))
fixed=$(comm -13 <(printf '%s\n' "$failed") <(printf '%s\n' "$baseline"))
count() { printf '%s' "$1" | grep -c . ; }
total=$(grep -E '^\s*Tests:' "$LOG" | tail -1 | grep -oE '[0-9]+ (failed|skipped|passed)' | awk '{s+=$1} END {print s}')
nb=$(count "$baseline")

echo "1. failures in the baseline (known, environmental): $(count "$known")"
echo "2. failures NOT in the baseline — THE GATE TRIPS:   $(count "$new")"; [ -n "$new" ] && printf '%s\n' "$new" | sed 's/^/     /'
echo "3. baseline entries that now PASS — shrink the file: $(count "$fixed")"; [ -n "$fixed" ] && printf '%s\n' "$fixed" | sed 's/^/     /'
echo "blind spot: ${nb} baselined of ${total} tests ($(awk -v a="$nb" -v b="$total" 'BEGIN { printf "%.1f", (b ? 100*a/b : 0) }')%) cannot report a regression inside themselves"

if [ -n "$new" ]; then exit 1; fi
if [ -n "$fixed" ]; then exit 2; fi
echo "GATE: green against the baseline"
exit 0
