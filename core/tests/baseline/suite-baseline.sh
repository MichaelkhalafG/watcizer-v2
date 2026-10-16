#!/usr/bin/env bash
# The suite as a GATE against a known-red baseline (TEST-LIVE-ROWS, decided 2026-10-15).
#
# The local database is a production copy, so a known set of tests fails on production's rows
# rather than on the code. Until the frozen test database is built (deferred, costed on the
# backlog), this script runs the full suite and compares the FAILING SET, by test name, against
# tests/baseline/suite-baseline.txt, and prints these lines:
#   0. the STATE it measured in: demo variants ON or OFF (see below), and which baseline applies
#   1. failures that ARE in the baseline            — known, environmental
#   2. failures that are NOT in the baseline         — THE GATE TRIPS (exit 1)
#   3. baseline entries that now PASS                — the baseline is stale; shrink it (exit 2)
#   4. tables the suite CHANGED                       — a test wrote OUTSIDE its transaction (exit 3)
#   5. mysql.db CHECK TABLE before / after            — under observation since 2026-10-16 (Aria repair)
# It exits 0 only when the second, third and fourth lines are empty.
#
# STATE (2026-10-16): DemoVariantsSeeder may be applied to the dev database (205 `DEMO-` variant
# rows on 33 products, for the B3/B5 build). Two tests fail BY DESIGN while it is on. Their baseline
# lines carry the tag `[demo-on]` in the cause; with the demo ON they count as known, with the demo
# OFF they are excluded from the baseline — so they must PASS, and the gate trips if they do not.
# One file, two declared states, and line 0 says which one was measured. Never two numbers in
# circulation without the file saying which state each belongs to.
#
# Line 4 (FIXTURE-LEAK, 2026-10-16): the suite runs inside rolled-back transactions, so a full run must
# leave every table identical. The checksum is CONTENT-ONLY (GATE-VIRTUAL-CHECKSUM, 2026-10-16):
# CHECKSUM TABLE hashes the record buffer, and on the one table with a VIRTUAL generated column
# (`storefront_category_product.primary_guard`) that value moved between runs with every row identical
# to the export. So, per table: COUNT(*) and the sum of CRC32 over every STORED column (information_schema,
# generated columns excluded), NULL spelled `<NULL>` so it differs from ''. Order-independent,
# deterministic, and shown to report a one-cell change on a scratch copy (RUN_20261016.md row 8b).
# With `--log` there is no run, so there is no checksum pair and line 4 reads "not measured".
#
# What it does NOT catch: a real regression that lands INSIDE one of the baselined tests — that
# test is already red, so it cannot turn red. The size of that blind spot is printed with the
# result (baselined tests / tests in the suite). Keep the baseline small; every entry is a test
# that is not guarding anything.
#
# Usage, from core/:
#   bash tests/baseline/suite-baseline.sh                 # runs php artisan test, then compares
#   bash tests/baseline/suite-baseline.sh --log F         # compares an existing plain-text Pest log F
#   bash tests/baseline/suite-baseline.sh --checksum DB F # writes the content checksum of database DB to F, nothing else
set -u
cd "$(dirname "$0")/../.." || exit 3
BASELINE="tests/baseline/suite-baseline.txt"
MYSQL="${MYSQL_BIN:-/c/xampp/mysql/bin/mysql.exe}"
DBNAME="${DB_DATABASE:-$(grep -E '^DB_DATABASE=' .env | cut -d= -f2- | tr -d '"\r')}"
sql() { "$MYSQL" -u root --protocol=tcp -h 127.0.0.1 -N -e "$1" 2>/dev/null | tr -d '\r'; }   # the Windows client ends each line with CR

checksum_all() { # $1 = out file, $2 = database (default: the app's). One line per table: name, rows, content sum.
  local out="$1" db="${2:-$DBNAME}" t cols
  : > "$out"
  for t in $(sql "SELECT table_name FROM information_schema.tables WHERE table_schema='$db' AND table_type='BASE TABLE' ORDER BY 1"); do
    cols=$(sql "SELECT column_name FROM information_schema.columns WHERE table_schema='$db' AND table_name='$t' AND is_generated='NEVER' ORDER BY ordinal_position" \
      | awk 'BEGIN{ORS=""} {printf "%sIFNULL(`%s`,\047<NULL>\047)", (NR>1?",":""), $1}')
    [ -n "$cols" ] || { echo "$t no-stored-columns" >> "$out"; continue; }
    sql "SELECT COUNT(*), COALESCE(SUM(CRC32(CONCAT_WS('|', $cols))), 0) FROM \`$db\`.\`$t\`" | awk -v t="$t" '{print t, $1, $2}' >> "$out"
  done
}
check_mysql_db() { sql "CHECK TABLE mysql.db" | awk -F'\t' '{printf "%s%s: %s", (NR>1?"; ":""), $3, $4}'; }

if [ "${1:-}" = "--checksum" ]; then
  [ -n "${2:-}" ] && [ -n "${3:-}" ] || { echo "usage: --checksum DB OUTFILE" >&2; exit 3; }
  checksum_all "$3" "$2"; echo "content checksum of $2: $(wc -l < "$3") tables → $3"; exit 0
fi

LOG=""
if [ "${1:-}" = "--log" ]; then LOG="${2:-}"; [ -f "$LOG" ] || { echo "no such log: $LOG" >&2; exit 3; }; fi

# Line 0 — the state. The demo is ON when DEMO- variant rows exist (DemoVariantsSeeder).
demo_rows=$(sql "SELECT COUNT(*) FROM \`$DBNAME\`.catalog_product_variants WHERE sku LIKE 'DEMO-%'")
demo_rows="${demo_rows:-0}"
if [ "$demo_rows" -gt 0 ] 2>/dev/null; then STATE="demo-on"; else STATE="demo-off"; fi
pid=$(tasklist 2>/dev/null | awk '/^mysqld.exe/ {print $2; exit}')
echo "0. state: demo variants ${STATE#demo-} (${demo_rows} DEMO rows) — mysqld pid ${pid:-?} — database $DBNAME"

NOT_MEASURED="not measured (--log mode: no run, no checksum pair)"
changed="$NOT_MEASURED"
mysqldb_before="$NOT_MEASURED"; mysqldb_after="$NOT_MEASURED"
if [ -z "$LOG" ]; then
  LOG="$(mktemp -t suite-baseline-XXXXXX).log"
  before="${LOG%.log}.before.txt"; after="${LOG%.log}.after.txt"
  mysqldb_before=$(check_mysql_db)
  cstart=$(date +%s); checksum_all "$before"; echo "checksum before: $(wc -l < "$before") tables in $(( $(date +%s) - cstart )) s"
  start=$(date +%s)
  php artisan test 2>&1 | sed -E 's/\x1b\[[0-9;]*m//g' > "$LOG"
  echo "suite: $(grep -E '^\s*Tests:' "$LOG" | tail -1 | sed -E 's/^\s*//') — $(( $(date +%s) - start )) s — log $LOG"
  cstart=$(date +%s); checksum_all "$after"; echo "checksum after:  $(wc -l < "$after") tables in $(( $(date +%s) - cstart )) s"
  mysqldb_after=$(check_mysql_db)
  changed=$(diff "$before" "$after" | grep '^>' | awk '{print $2}')
else
  echo "suite (from log): $(grep -E '^\s*Tests:' "$LOG" | tail -1 | sed -E 's/^\s*//')"
fi
grep -qE '^\s*Tests:' "$LOG" || { echo "the log holds no Pest summary — not a full run" >&2; exit 3; }

# The failing set, by "Class > name", exactly as Pest prints the FAILED lines (the name may be
# truncated by Pest with an ellipsis; the baseline stores the same truncated form). When a test
# dies on an EXCEPTION rather than an assertion, Pest appends the exception class to the same line,
# right-aligned after a run of spaces ("… without a sign-in                PDOException") — that
# suffix is stripped, so one test is one name whichever way it failed (seen 2026-10-16: a baselined
# test failed on a transient `SQLSTATE[HY000] [2002]` and read as both NEW and STALE).
failed=$(grep -E '^\s*FAILED\s+Tests\\' "$LOG" | sed -E 's/^\s*FAILED\s+//; s/\s+$//; s/\s{2,}[A-Za-z0-9_\\]+$//' | sort -u)
entries=$(grep -vE '^\s*(#|$)' "$BASELINE")
demo_entries=$(printf '%s\n' "$entries" | grep -c '| \[demo-on\]' || true)
if [ "$STATE" = "demo-off" ]; then entries=$(printf '%s\n' "$entries" | grep -v '| \[demo-on\]'); fi
baseline=$(printf '%s\n' "$entries" | sed -E 's/\s*\|.*$//; s/\s+$//' | sort -u)

known=$(comm -12 <(printf '%s\n' "$failed") <(printf '%s\n' "$baseline"))
new=$(comm -23 <(printf '%s\n' "$failed") <(printf '%s\n' "$baseline"))
fixed=$(comm -13 <(printf '%s\n' "$failed") <(printf '%s\n' "$baseline"))
count() { printf '%s' "$1" | grep -c . ; }
total=$(grep -E '^\s*Tests:' "$LOG" | tail -1 | grep -oE '[0-9]+ (failed|skipped|passed)' | awk '{s+=$1} END {print s}')
nb=$(count "$baseline")

if [ "$STATE" = "demo-on" ]; then
  echo "   baseline in force: ${nb} entries, ${demo_entries} of them tagged [demo-on] and counted because the demo is ON"
else
  echo "   baseline in force: ${nb} entries; the ${demo_entries} tagged [demo-on] are EXCLUDED because the demo is OFF — they must pass"
fi
echo "1. failures in the baseline (known, environmental): $(count "$known")"
echo "2. failures NOT in the baseline — THE GATE TRIPS:   $(count "$new")"; [ -n "$new" ] && printf '%s\n' "$new" | sed 's/^/     /'
echo "3. baseline entries that now PASS — shrink the file: $(count "$fixed")"; [ -n "$fixed" ] && printf '%s\n' "$fixed" | sed 's/^/     /'
if [ "$changed" = "$NOT_MEASURED" ]; then
  echo "4. tables the suite CHANGED (wrote outside its transaction): $changed"
else
  echo "4. tables the suite CHANGED (wrote outside its transaction): $(count "$changed")"; [ -n "$changed" ] && printf '%s\n' "$changed" | sed 's/^/     /'
fi
echo "5. mysql.db CHECK TABLE — before: ${mysqldb_before} | after: ${mysqldb_after}"
echo "blind spot: ${nb} baselined of ${total} tests ($(awk -v a="$nb" -v b="$total" 'BEGIN { printf "%.1f", (b ? 100*a/b : 0) }')%) cannot report a regression inside themselves"

if [ -n "$new" ]; then exit 1; fi
if [ -n "$fixed" ]; then exit 2; fi
if [ -n "$changed" ] && [ "$changed" != "$NOT_MEASURED" ]; then echo "GATE: the suite MUTATED the database it measures — a test commits outside its transaction"; exit 3; fi
if [ "$changed" = "$NOT_MEASURED" ]; then
  echo "GATE: green against the baseline (state: demo ${STATE#demo-}); the database was not measured (--log mode)"
else
  echo "GATE: green against the baseline (state: demo ${STATE#demo-}), and the database content is identical before and after"
fi
exit 0
