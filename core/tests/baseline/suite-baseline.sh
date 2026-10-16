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
#   4. tables the suite CHANGED                       — a test wrote OUTSIDE its transaction (exit 3)
# It exits 0 only when the second, third and fourth lines are empty.
#
# Line 4 (FIXTURE-LEAK, 2026-10-16): the suite runs inside rolled-back transactions, so a full run must
# leave every table byte-identical. CHECKSUM TABLE is taken for every table before `php artisan test`
# and again after; a table whose checksum moved was committed to by a test — the way the fixture
# mailbox row reached the dev database on 2026-10-14 (UserWriteGuardTest committed the suite's
# transaction). With `--log` there is no run, so there is no checksum pair and line 4 reads "not measured".
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
MYSQL="${MYSQL_BIN:-/c/xampp/mysql/bin/mysql.exe}"
DBNAME="${DB_DATABASE:-$(grep -E '^DB_DATABASE=' .env | cut -d= -f2- | tr -d '"\r')}"
checksum_all() { # one CHECKSUM TABLE per table (a single statement for 140 tables overflows the shell)
  : > "$1"
  for t in $("$MYSQL" -u root --protocol=tcp -h 127.0.0.1 -N -e "SELECT table_name FROM information_schema.tables WHERE table_schema='$DBNAME' ORDER BY 1" 2>/dev/null | tr -d '\r'); do   # the Windows client ends each line with CR
    "$MYSQL" -u root --protocol=tcp -h 127.0.0.1 -N -e "CHECKSUM TABLE \`$DBNAME\`.\`$t\`" 2>/dev/null | awk -v t="$t" '{print t, $2}' >> "$1"
  done
}
NOT_MEASURED="not measured (--log mode: no run, no checksum pair)"
changed="$NOT_MEASURED"
if [ -z "$LOG" ]; then
  LOG="$(mktemp -t suite-baseline-XXXXXX).log"
  before="${LOG%.log}.before.txt"; after="${LOG%.log}.after.txt"
  cstart=$(date +%s); checksum_all "$before"; echo "checksum before: $(wc -l < "$before") tables in $(( $(date +%s) - cstart )) s"
  start=$(date +%s)
  php artisan test 2>&1 | sed -E 's/\x1b\[[0-9;]*m//g' > "$LOG"
  echo "suite: $(grep -E '^\s*Tests:' "$LOG" | tail -1 | sed -E 's/^\s*//') — $(( $(date +%s) - start )) s — log $LOG"
  cstart=$(date +%s); checksum_all "$after"; echo "checksum after:  $(wc -l < "$after") tables in $(( $(date +%s) - cstart )) s"
  changed=$(diff "$before" "$after" | grep '^>' | awk '{print $2}')
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
if [ "$changed" = "$NOT_MEASURED" ]; then
  echo "4. tables the suite CHANGED (wrote outside its transaction): $changed"
else
  echo "4. tables the suite CHANGED (wrote outside its transaction): $(count "$changed")"; [ -n "$changed" ] && printf '%s\n' "$changed" | sed 's/^/     /'
fi
echo "blind spot: ${nb} baselined of ${total} tests ($(awk -v a="$nb" -v b="$total" 'BEGIN { printf "%.1f", (b ? 100*a/b : 0) }')%) cannot report a regression inside themselves"

if [ -n "$new" ]; then exit 1; fi
if [ -n "$fixed" ]; then exit 2; fi
if [ -n "$changed" ] && [ "$changed" != "$NOT_MEASURED" ]; then echo "GATE: the suite MUTATED the database it measures — a test commits outside its transaction"; exit 3; fi
echo "GATE: green against the baseline, and the database is byte-identical before and after"
exit 0
